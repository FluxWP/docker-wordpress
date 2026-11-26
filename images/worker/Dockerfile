# FluxWP worker image: wp-cli + one-time setup + WP-Cron runner.
# Build from the repository root:
#   docker build -f images/worker/Dockerfile .
ARG BASE=wordpress:cli-2.12-php8.4
FROM ${BASE}

COPY --chmod=755 images/min-php.sh /usr/local/bin/flx-min-php
RUN /usr/local/bin/flx-min-php

# PhpRedis (built with igbinary), same as the web image, so the worker reads and writes the
# cache in the same format as WordPress.
USER root
RUN set -eux; \
    apk add --no-cache --virtual .flx-build $PHPIZE_DEPS; \
    pecl install igbinary; \
    docker-php-ext-enable igbinary; \
    pecl install --configureoptions 'enable-redis-igbinary="yes" enable-redis-lzf="no" enable-redis-zstd="no" enable-redis-msgpack="no" enable-redis-lz4="no"' redis; \
    docker-php-ext-enable --ini-name zz-redis.ini redis; \
    apk del .flx-build; \
    rm -rf /tmp/pear; \
    php -r 'exit(extension_loaded("redis") && extension_loaded("igbinary") && defined("Redis::SERIALIZER_IGBINARY") ? 0 : 1);'

COPY config/php/zz-flux.ini /usr/local/etc/php/conf.d/zz-flux.ini
COPY config/wordpress/wp-config-extra.php /usr/local/etc/flux/wp-config-extra.php
COPY config/wordpress/flx-docker.php /usr/local/etc/flux/flx-docker.php
COPY config/wordpress/flx-realtime.php /usr/local/etc/flux/flx-realtime.php
COPY --chmod=755 scripts/worker.sh /usr/local/bin/flx-worker
COPY scripts/setup-zip.php /usr/local/bin/flx-setup-zip.php

ENV WORDPRESS_CONFIG_EXTRA="if (is_file('/usr/local/etc/flux/wp-config-extra.php')) { require '/usr/local/etc/flux/wp-config-extra.php'; }" \
    WP_CRON_MODE=worker \
    FLX_BACKGROUND_WAKE=1 \
    HOME=/tmp \
    WP_CLI_CACHE_DIR=/tmp/wp-cli-cache

# The cli base image is not root. A fresh named volume mounted here inherits this ownership.
RUN mkdir -p /run/flx-private && chown 33:33 /run/flx-private && chmod 0700 /run/flx-private

# Same uid as www-data in the Debian-based wordpress image, so files stay writable.
USER 33:33
ENTRYPOINT ["/usr/local/bin/flx-worker"]

LABEL org.opencontainers.image.source="https://github.com/FluxWP/docker-wordpress" \
      org.opencontainers.image.description="FluxWP worker: automatic WordPress install, Redis and Flux Setup bootstrap, WP-Cron" \
      org.opencontainers.image.licenses="MIT"
