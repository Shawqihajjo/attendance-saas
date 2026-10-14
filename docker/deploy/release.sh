#!/bin/sh
# Apply platform migrations, then tenant migrations, then start the application.
# Run from a checkout that has .env.prod filled from the secret store.
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

compose() {
    docker compose --env-file "$env_file" -f docker-compose.prod.yml -p "${COMPOSE_PROJECT_NAME:-attendance}" "$@"
}

compose build
compose up -d mysql redis rabbitmq
compose run --rm php php bin/console doctrine:migrations:migrate --no-interaction --env=prod
compose run --rm php php bin/console app:tenant:migrate --all --no-interaction --env=prod
compose up -d
compose exec -T php php bin/console app:diagnostics
