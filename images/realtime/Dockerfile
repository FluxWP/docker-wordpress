# FluxWP Realtime image: WebSocket fan-out of WordPress signals from Redis (one site per stack).
# Build from the repository root:
#   docker build -f images/realtime/Dockerfile .
ARG BASE=oven/bun:1.4-alpine
FROM ${BASE}

WORKDIR /app
COPY images/realtime/server.ts /app/server.ts

ENV NODE_ENV=production \
    PORT=80
# Docker lets unprivileged users bind low ports (net.ipv4.ip_unprivileged_port_start=0), so no root needed.
USER bun
EXPOSE 80
HEALTHCHECK --interval=15s --timeout=5s --retries=5 CMD wget -q -O /dev/null http://127.0.0.1/healthz || exit 1
CMD ["bun", "/app/server.ts"]

LABEL org.opencontainers.image.source="https://github.com/FluxWP/docker-wordpress" \
      org.opencontainers.image.description="FluxWP Realtime: WebSocket signals from WordPress over Redis" \
      org.opencontainers.image.licenses="MIT"
