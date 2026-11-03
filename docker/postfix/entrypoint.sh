#!/bin/sh
# Configures Postfix for the integration tests and runs it in the foreground.
#
#   25  smtp        STARTTLS offered, AUTH only after STARTTLS
#   465 smtps       implicit TLS, AUTH
#   587 submission  STARTTLS required, AUTH required
#   2526            postscreen in front of smtpd: rejects clients that talk before the greeting
#
# For the default hostname mail.example.com the certificate from tls/ (issued by the test CA
# tls/ca.pem) is used; for any other hostname a self-signed certificate is generated.
#
# SMTP_POSTCONF can hold extra main.cf settings, one per line, applied last. E.g. to make the
# server vulnerable to SMTP smuggling again and watch examples/MalformedInputTest.php fail:
#   SMTP_POSTCONF="smtpd_forbid_bare_newline = no"
set -eu

: "${SMTP_DOMAIN:=example.com}"
: "${SMTP_HOSTNAME:=mail.$SMTP_DOMAIN}"
: "${SMTP_USERNAME:=username}"
: "${SMTP_PASSWORD:=password}"
: "${SMTP_MAILBOX:=existing}"   # local mailbox that exists: $SMTP_MAILBOX@$SMTP_DOMAIN
: "${SMTP_POSTCONF:=}"

[ -f /etc/postfix/main.cf ] || cp /usr/share/postfix/main.cf.debian /etc/postfix/main.cf

# TLS certificate
TLS_DIR=/etc/postfix/tls
if [ ! -f "$TLS_DIR/cert.pem" ]; then
    mkdir -p "$TLS_DIR"
    if [ "$SMTP_HOSTNAME" = mail.example.com ]; then
        cp /opt/test-tls/server.pem "$TLS_DIR/cert.pem"
        cp /opt/test-tls/server.key "$TLS_DIR/key.pem"
    else
        openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=$SMTP_HOSTNAME" \
            -addext "subjectAltName=DNS:$SMTP_HOSTNAME" \
            -keyout "$TLS_DIR/key.pem" -out "$TLS_DIR/cert.pem" 2>/dev/null
    fi
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
    "smtpd_relay_restrictions = permit_mynetworks permit_sasl_authenticated defer_unauth_destination" \
    "smtpd_forbid_bare_newline = yes" \
    "postscreen_greet_action = enforce" \
    "postscreen_greet_wait = 1s" \
    "postscreen_cache_map ="

# Run without chroot so smtpd can read /etc/sasldb2
postconf -F '*/*/chroot = n'
postconf -M "submission/inet = submission inet n - n - - smtpd"
postconf -P "submission/inet/smtpd_tls_security_level = encrypt"
postconf -P "submission/inet/smtpd_client_restrictions = permit_sasl_authenticated,reject"
postconf -M "smtps/inet = smtps inet n - n - - smtpd"
postconf -P "smtps/inet/smtpd_tls_wrappermode = yes"
# postscreen on 2526 hands clients that pass to the "smtpd" pass service. Its cache is
# disabled (postscreen_cache_map above) so every connection is tested, not just the first.
postconf -M "2526/inet = 2526 inet n - n - 1 postscreen"
postconf -M "smtpd/pass = smtpd pass - - n - - smtpd"

printf '%s\n' "$SMTP_POSTCONF" | while IFS= read -r setting; do
    if [ -n "$setting" ]; then postconf -e "$setting"; fi
done

newaliases
exec postfix start-fg
