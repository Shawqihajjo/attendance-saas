FROM node:22-alpine AS frontend

WORKDIR /app

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./
RUN npm run build

FROM nginx:1.28-alpine

RUN mkdir -p /etc/nginx/available \
    && rm -f /etc/nginx/conf.d/default.conf
COPY docker/nginx/edge.conf docker/nginx/terminate.conf /etc/nginx/available/
COPY docker/nginx/snippets/ /etc/nginx/snippets/
COPY docker/nginx/select-config.sh /docker-entrypoint.d/40-attendance-config.sh
COPY --from=frontend /app/dist /var/www/frontend
RUN chmod +x /docker-entrypoint.d/40-attendance-config.sh
