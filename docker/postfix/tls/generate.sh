#!/bin/sh
# Generates the TEST-ONLY CA and server certificate used by the Postfix test server, so the
# example tests can verify the certificate against ca.pem and the expected hostname.
# The keys are public (committed); never trust ca.pem anywhere else.
set -eu
cd "$(dirname "$0")"
HOST=mail.example.com
DAYS=36500

openssl req -x509 -newkey rsa:2048 -nodes -days "$DAYS" -sha256 \
    -subj "/O=smtp-tool/CN=smtp-tool test CA" \
    -addext "basicConstraints=critical,CA:TRUE" \
    -addext "keyUsage=critical,keyCertSign,cRLSign" \
    -keyout ca.key -out ca.pem

openssl req -newkey rsa:2048 -nodes -sha256 -subj "/CN=$HOST" -keyout server.key -out server.csr
printf 'basicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:%s\n' "$HOST" > server.ext
openssl x509 -req -in server.csr -CA ca.pem -CAkey ca.key -CAcreateserial -days "$DAYS" -sha256 \
    -extfile server.ext -out server.pem
# The CA key is not needed afterwards; without it nobody can issue more certificates from this CA
rm -f server.csr server.ext ca.srl ca.key
