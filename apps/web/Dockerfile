FROM oven/bun:1.4.3-slim AS dependencies

WORKDIR /app

COPY apps/web/package.json apps/web/bun.lock ./
RUN bun install --frozen-lockfile

FROM dependencies AS build

COPY apps/web/ ./
RUN bun run --bun build

FROM oven/bun:1.4.3-slim AS runtime

LABEL org.opencontainers.image.source="https://github.com/marcel-breuer/metricove"

WORKDIR /app
ENV NODE_ENV=production
ENV HOST=0.0.0.0
ENV PORT=3000

COPY --from=build --chown=bun:bun /app/build ./build

USER bun
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 CMD ["bun", "-e", "fetch('http://127.0.0.1:3000/health').then((response) => process.exit(response.ok ? 0 : 1)).catch(() => process.exit(1))"]
CMD ["bun", "./build"]
