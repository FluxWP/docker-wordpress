# FluxWP worker image: wp-cli + one-time setup + WP-Cron runner.
# Build from the repository root:
#   docker build -f images/worker/Dockerfile .
ARG BASE=wordpress:cli-2.12-php8.4
FROM ${BASE}

COPY --chmod=755 images/min-php.sh /usr/local/bin/flx-min-php
RUN /usr/local/bin/flx-min-php

COPY config/php/zz-flux.ini /usr/local/etc/php/conf.d/zz-flux.ini
COPY config/wordpress/wp-config-extra.php /usr/local/etc/flux/wp-config-extra.php
COPY config/wordpress/flx-docker.php /usr/local/etc/flux/flx-docker.php
COPY --chmod=755 scripts/worker.sh /usr/local/bin/flx-worker

ENV WORDPRESS_CONFIG_EXTRA="if (is_file('/usr/local/etc/flux/wp-config-extra.php')) { require '/usr/local/etc/flux/wp-config-extra.php'; }" \
    WP_CRON_MODE=worker \
    HOME=/tmp \
    WP_CLI_CACHE_DIR=/tmp/wp-cli-cache

# Same uid as www-data in the Debian-based wordpress image, so files stay writable.
USER 33:33
ENTRYPOINT ["/usr/local/bin/flx-worker"]

LABEL org.opencontainers.image.source="https://github.com/FluxWP/docker-wordpress" \
      org.opencontainers.image.description="FluxWP worker: automatic WordPress install, Redis and Flux Setup bootstrap, WP-Cron" \
      org.opencontainers.image.licenses="MIT"
