# Minimal Postfix server for the integration tests (see docker-compose.yml).
# NOT meant for real mail: self-signed certificate, test credentials, VRFY enabled.
FROM ubuntu:24.04

RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        postfix sasl2-bin libsasl2-modules openssl \
 && rm -rf /var/lib/apt/lists/*

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 25 465 587
HEALTHCHECK --interval=60s --timeout=3s --start-period=30s --start-interval=1s CMD postfix status

ENTRYPOINT ["/entrypoint.sh"]
