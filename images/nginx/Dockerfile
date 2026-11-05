# FluxWP nginx image for the nginx-fpm stack.
# Build from the repository root:
#   docker build -f images/nginx/Dockerfile .
ARG BASE=nginx:1.30-alpine
FROM ${BASE}

COPY config/nginx/default.conf /etc/nginx/conf.d/default.conf

LABEL org.opencontainers.image.source="https://github.com/FluxWP/docker-wordpress" \
      org.opencontainers.image.description="nginx in front of the FluxWP WordPress PHP-FPM image" \
      org.opencontainers.image.licenses="MIT"
