# FluxWP WhatsApp API (flx-wpapi) image: Evolution API v2 compatible bridge on Baileys.
# Source: https://github.com/FluxWP/whatsapp-api (MIT). Build from the repository root:
#   docker build -f images/wpapi/Dockerfile .
ARG BASE=node:22-alpine
FROM ${BASE}

# Branch or tag of FluxWP/whatsapp-api. The daily image rebuild picks up new commits on main.
ARG WPAPI_REF=main
RUN apk add --no-cache git \
 && git clone --depth 1 --branch "${WPAPI_REF}" https://github.com/FluxWP/whatsapp-api.git /app \
 && rm -rf /app/.git \
 && apk del git

WORKDIR /app
# ffmpeg-static (optional dependency) converts voice notes to ogg/opus; musl needs no extra libs.
RUN npm install --omit=dev --no-audit --no-fund && npm cache clean --force \
 && mkdir -p /data && chown -R node:node /app /data

ENV NODE_ENV=production \
    PORT=3000 \
    FLXW_DATA_DIR=/data
USER node
EXPOSE 3000
CMD ["node", "src/index.js"]

LABEL org.opencontainers.image.source="https://github.com/FluxWP/whatsapp-api" \
      org.opencontainers.image.description="FluxWP WhatsApp API (flx-wpapi): Evolution API v2 compatible WhatsApp bridge" \
      org.opencontainers.image.licenses="MIT"
