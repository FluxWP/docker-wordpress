# FluxWP WordPress image: the official wordpress image + FluxWP defaults.
# Build from the repository root:
#   docker build -f images/wordpress/Dockerfile --build-arg BASE=wordpress:7.1-php8.4-apache .
ARG BASE=wordpress:7.1-php8.4-apache
FROM ${BASE}

COPY --chmod=755 images/min-php.sh /usr/local/bin/flx-min-php
RUN /usr/local/bin/flx-min-php

# PhpRedis (built with igbinary) for the Redis object cache; Flux Redis picks it up on its own.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install igbinary; \
    docker-php-ext-enable igbinary; \
    pecl install --configureoptions 'enable-redis-igbinary="yes" enable-redis-lzf="no" enable-redis-zstd="no" enable-redis-msgpack="no" enable-redis-lz4="no"' redis; \
    docker-php-ext-enable --ini-name zz-redis.ini redis; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/* /tmp/pear; \
    php -r 'exit(extension_loaded("redis") && extension_loaded("igbinary") && defined("Redis::SERIALIZER_IGBINARY") ? 0 : 1);'

COPY config/php/zz-flux.ini /usr/local/etc/php/conf.d/zz-flux.ini
COPY config/wordpress/wp-config-extra.php /usr/local/etc/flux/wp-config-extra.php
COPY config/apache/zz-flux.conf /usr/local/share/flux/apache.conf
COPY config/php-fpm/zz-flux.conf /usr/local/share/flux/php-fpm.conf

# Apache variant gets the Apache overrides, FPM variant the pool overrides.
RUN set -eux; \
    if [ -d /etc/apache2/conf-enabled ]; then \
        cp /usr/local/share/flux/apache.conf /etc/apache2/conf-enabled/zz-flux.conf; \
    fi; \
    if [ -d /usr/local/etc/php-fpm.d ]; then \
        cp /usr/local/share/flux/php-fpm.conf /usr/local/etc/php-fpm.d/zz-flux.conf; \
    fi

# Load the FluxWP settings from wp-config.php; override in compose if needed.
ENV WORDPRESS_CONFIG_EXTRA="if (is_file('/usr/local/etc/flux/wp-config-extra.php')) { require '/usr/local/etc/flux/wp-config-extra.php'; }" \
    WP_CRON_MODE=worker \
    APACHE_MAX_WORKERS=20

LABEL org.opencontainers.image.source="https://github.com/FluxWP/docker-wordpress" \
      org.opencontainers.image.description="WordPress with FluxWP defaults: tuned PHP/OPcache, upload limits, hardening, Redis-ready" \
      org.opencontainers.image.licenses="MIT"
