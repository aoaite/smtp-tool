#!/bin/sh
# Configures Postfix for the integration tests and runs it in the foreground.
#
#   25  smtp        STARTTLS offered, AUTH only after STARTTLS
#   465 smtps       implicit TLS, AUTH
#   587 submission  STARTTLS required, AUTH
#
# A self-signed certificate is generated on first start.
set -eu

: "${SMTP_DOMAIN:=example.com}"
: "${SMTP_HOSTNAME:=mail.$SMTP_DOMAIN}"
: "${SMTP_USERNAME:=username}"
: "${SMTP_PASSWORD:=password}"
: "${SMTP_MAILBOX:=existing}"   # local mailbox that exists: $SMTP_MAILBOX@$SMTP_DOMAIN

[ -f /etc/postfix/main.cf ] || cp /usr/share/postfix/main.cf.debian /etc/postfix/main.cf

# TLS certificate
TLS_DIR=/etc/postfix/tls
if [ ! -f "$TLS_DIR/cert.pem" ]; then
    mkdir -p "$TLS_DIR"
    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=$SMTP_HOSTNAME" \
        -keyout "$TLS_DIR/key.pem" -out "$TLS_DIR/cert.pem" 2>/dev/null
    chmod 600 "$TLS_DIR/key.pem"
fi

# Mailbox and SASL user
id "$SMTP_MAILBOX" >/dev/null 2>&1 || useradd --no-create-home --shell /usr/sbin/nologin "$SMTP_MAILBOX"
mkdir -p /etc/postfix/sasl
cat > /etc/postfix/sasl/smtpd.conf <<CONF
pwcheck_method: auxprop
auxprop_plugin: sasldb
mech_list: PLAIN LOGIN
CONF
printf '%s' "$SMTP_PASSWORD" | saslpasswd2 -p -c -u "$SMTP_HOSTNAME" "$SMTP_USERNAME"
chown root:postfix /etc/sasldb2
chmod 640 /etc/sasldb2

postconf -e \
    "myhostname = $SMTP_HOSTNAME" \
    "mydomain = $SMTP_DOMAIN" \
    "mydestination = \$myhostname, localhost, $SMTP_DOMAIN" \
    "mynetworks = 127.0.0.0/8" \
    "inet_interfaces = all" \
    "inet_protocols = ipv4" \
    "maillog_file = /dev/stdout" \
    "smtpd_banner = \$myhostname ESMTP" \
    "disable_vrfy_command = no" \
    "smtpd_tls_cert_file = $TLS_DIR/cert.pem" \
    "smtpd_tls_key_file = $TLS_DIR/key.pem" \
    "smtpd_tls_security_level = may" \
    "smtpd_tls_auth_only = yes" \
    "smtpd_sasl_auth_enable = yes" \
    "smtpd_sasl_type = cyrus" \
    "smtpd_sasl_path = smtpd" \
    "smtpd_sasl_local_domain = \$myhostname" \
    "smtpd_relay_restrictions = permit_mynetworks permit_sasl_authenticated defer_unauth_destination"

# Run without chroot so smtpd can read /etc/sasldb2
postconf -F '*/*/chroot = n'
postconf -M "submission/inet = submission inet n - n - - smtpd"
postconf -P "submission/inet/smtpd_tls_security_level = encrypt"
postconf -M "smtps/inet = smtps inet n - n - - smtpd"
postconf -P "smtps/inet/smtpd_tls_wrappermode = yes"

newaliases
exec postfix start-fg
