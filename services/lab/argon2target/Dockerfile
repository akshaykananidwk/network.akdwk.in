# A PHP whose Argon2 comes from libsodium.
#
# The production box's password_hash() threw
#
#   ValueError: A thread value other than 1 is not supported by this implementation
#
# and the installer died on the "create the administrator" step. No PHP
# available as a package reproduces it: every distribution and every official
# PHP image links libargon2, and libargon2 accepts any thread count. The
# difference is a build option — PHP falls back to libsodium's crypto_pwhash
# for Argon2id when it was not built against libargon2 — and aaPanel compiles
# its own PHP.
#
# So this builds one. Only what Crypto.php touches is enabled, which keeps the
# compile to a few minutes: the point is the password API, not a second web
# stack.
FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive
ARG PHP_VERSION=8.3.26

RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential autoconf bison re2c pkg-config file \
        libxml2-dev libsodium-dev libssl-dev \
        ca-certificates xz-utils \
    && rm -rf /var/lib/apt/lists/*

# Deliberately NOT installing libargon2-dev. Its absence is the whole point:
# with it, PASSWORD_ARGON2_PROVIDER is "standard" and the defect cannot happen.
#
# The tarball is fetched by run.sh and copied in rather than downloaded here,
# so the build needs no network of its own and no trust store beyond the one
# the host already has.
COPY php.tar.xz /tmp/php.tar.xz
RUN mkdir -p /usr/src/php \
    && tar -xf /tmp/php.tar.xz -C /usr/src/php --strip-components=1 \
    && rm /tmp/php.tar.xz

WORKDIR /usr/src/php
RUN ./configure \
        --disable-all \
        --disable-cgi \
        --enable-cli \
        --with-sodium \
        --with-openssl \
        --enable-json \
        --without-pear \
    && make -j"$(nproc)" \
    && make install \
    && rm -rf /usr/src/php

COPY probe.php /usr/local/bin/probe.php

ENTRYPOINT ["php", "/usr/local/bin/probe.php"]
