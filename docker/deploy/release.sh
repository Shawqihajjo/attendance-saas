#!/bin/sh
# Apply platform migrations, then tenant migrations, then start the application.
# Run from a checkout that has a private env file filled from the secret store.
#
# Modes:
#   DEPLOY_MODE=build (default) — build images from the local checkout
#   DEPLOY_MODE=pull            — pull immutable images (set IMAGE_TAG or ATTENDANCE_*_IMAGE)
#
# Optional:
#   BACKUP_BEFORE_MIGRATE=1     — dump platform + tenant DBs before migrations
#   HEALTHCHECK_URL             — base URL for HTTP probes (default http://127.0.0.1)
#   SKIP_HTTP_HEALTH=1          — skip wget/curl probes (Compose healthchecks still run)
set -eu

root="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
cd "$root"

env_file="${ENV_FILE:-.env.prod}"
if [ ! -f "$env_file" ]; then
    echo "Create ${env_file} from .env.prod.example and fill it from the secret store." >&2
    exit 1
fi

# shellcheck disable=SC1090
set -a
. "./${env_file}"
set +a

if [ "${APP_ENV:-}" != "prod" ] || [ "${APP_DEBUG:-}" != "0" ]; then
    echo "Refusing to deploy without APP_ENV=prod and APP_DEBUG=0." >&2
    exit 1
fi

deploy_mode="${DEPLOY_MODE:-build}"
image_tag="${IMAGE_TAG:-}"
health_base="${HEALTHCHECK_URL:-http://127.0.0.1}"
project="${COMPOSE_PROJECT_NAME:-attendance}"

if [ -n "$image_tag" ]; then
    owner_lc="$(printf '%s' "${GHCR_OWNER:-${GITHUB_REPOSITORY_OWNER:-}}" | tr '[:upper:]' '[:lower:]')"
    if [ -z "${ATTENDANCE_PHP_IMAGE:-}" ]; then
        if [ -z "$owner_lc" ]; then
            echo "Set ATTENDANCE_PHP_IMAGE or GHCR_OWNER when using IMAGE_TAG." >&2
            exit 1
        fi
        ATTENDANCE_PHP_IMAGE="ghcr.io/${owner_lc}/attendance-php:${image_tag}"
    fi
    if [ -z "${ATTENDANCE_NGINX_IMAGE:-}" ]; then
        if [ -z "$owner_lc" ]; then
            echo "Set ATTENDANCE_NGINX_IMAGE or GHCR_OWNER when using IMAGE_TAG." >&2
            exit 1
        fi
        ATTENDANCE_NGINX_IMAGE="ghcr.io/${owner_lc}/attendance-nginx:${image_tag}"
    fi
    export ATTENDANCE_PHP_IMAGE ATTENDANCE_NGINX_IMAGE
fi

compose() {
    docker compose --env-file "$env_file" -f docker-compose.prod.yml -p "$project" "$@"
}

echo "Deploy mode=${deploy_mode} project=${project}"
if [ -n "${ATTENDANCE_PHP_IMAGE:-}" ]; then
    echo "PHP image=${ATTENDANCE_PHP_IMAGE}"
fi
if [ -n "${ATTENDANCE_NGINX_IMAGE:-}" ]; then
    echo "Nginx image=${ATTENDANCE_NGINX_IMAGE}"
fi

case "$deploy_mode" in
    build)
        compose build
        ;;
    pull)
        if [ -z "${ATTENDANCE_PHP_IMAGE:-}" ] || [ -z "${ATTENDANCE_NGINX_IMAGE:-}" ]; then
            echo "DEPLOY_MODE=pull requires ATTENDANCE_PHP_IMAGE and ATTENDANCE_NGINX_IMAGE (or IMAGE_TAG + GHCR_OWNER)." >&2
            exit 1
        fi
        compose pull php nginx messenger
        ;;
    *)
        echo "Unknown DEPLOY_MODE=${deploy_mode} (use build or pull)." >&2
        exit 1
        ;;
esac

# --wait blocks until service healthchecks pass (Compose v2).
compose up -d --wait mysql redis rabbitmq

if [ "${BACKUP_BEFORE_MIGRATE:-0}" = "1" ]; then
    echo "Taking database backup before migrations…"
    backup_stamp="$(compose exec -T mysql /usr/local/bin/backup-databases.sh)"
    echo "Backup directory stamp: ${backup_stamp}"
fi

# Stop the worker before schema changes so it cannot process events against a half-migrated schema.
compose stop messenger 2>/dev/null || true

echo "Migrating platform database…"
compose run --rm php php bin/console doctrine:migrations:migrate --no-interaction --env=prod

echo "Migrating tenant databases (one at a time; first failure aborts)…"
compose run --rm php php bin/console app:tenant:migrate --all --no-interaction --env=prod

if [ "$deploy_mode" = "pull" ]; then
    compose up -d --no-build --remove-orphans
else
    compose up -d --remove-orphans
fi

echo "Running app:diagnostics…"
compose exec -T php php bin/console app:diagnostics

if [ "${SKIP_HTTP_HEALTH:-0}" != "1" ]; then
    echo "Probing HTTP health at ${health_base}…"
    probe() {
        path="$1"
        url="${health_base}${path}"
        if command -v curl >/dev/null 2>&1; then
            curl -fsS --max-time 15 "$url" >/dev/null
        elif command -v wget >/dev/null 2>&1; then
            wget -qO- --timeout=15 "$url" >/dev/null
        else
            echo "Neither curl nor wget is available for HTTP health checks." >&2
            exit 1
        fi
    }
    # Port 80 keeps /api/v1/health even when TLS_TERMINATION=nginx.
    probe /api/v1/health
    probe /api/v1/health/ready
    # Frontend: Vite build is served at /. A 200 from the document root means Nginx has the assets.
    if command -v curl >/dev/null 2>&1; then
        code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "${health_base}/")"
    else
        code="$(wget -S -O /dev/null --timeout=15 "${health_base}/" 2>&1 | awk '/HTTP\//{print $2; exit}')"
    fi
    case "$code" in
        200|301|302) ;;
        *)
            echo "Frontend probe at ${health_base}/ returned HTTP ${code}." >&2
            exit 1
            ;;
    esac
    echo "HTTP health checks passed."
fi

if [ -n "${DEPLOY_REVISION:-}" ]; then
    echo "Deployed revision: ${DEPLOY_REVISION}"
fi
echo "Release completed."
