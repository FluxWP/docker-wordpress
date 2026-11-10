# FluxWP nginx image for the nginx-fpm stack.
# Build from the repository root:
#   docker build -f images/nginx/Dockerfile .
ARG BASE=nginx:1.30-alpine
FROM ${BASE}

ENV WP_INSTALL_TOKEN=""
COPY config/nginx/default.conf /etc/nginx/templates/default.conf.template
COPY --chmod=755 scripts/nginx-entrypoint.sh /flx-entrypoint.sh
ENTRYPOINT ["/flx-entrypoint.sh"]

LABEL org.opencontainers.image.source="https://github.com/FluxWP/docker-wordpress" \
      org.opencontainers.image.description="nginx in front of the FluxWP WordPress PHP-FPM image" \
      org.opencontainers.image.licenses="MIT"
