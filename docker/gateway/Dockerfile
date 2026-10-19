FROM nginxinc/nginx-unprivileged:1.30.4-alpine3.24 AS runtime

LABEL org.opencontainers.image.source="https://github.com/marcel-breuer/metricove"

COPY docker/gateway/nginx.conf /etc/nginx/nginx.conf
