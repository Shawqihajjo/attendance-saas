# Deployment

Development, staging, and production run the same application. They do not share configuration. Development is `docker-compose.yml`. Staging and production are `docker-compose.prod.yml` with different secret files and different data volumes.

| | Development | Staging | Production |
| --- | --- | --- | --- |
| Compose file | `docker-compose.yml` | `docker-compose.prod.yml` | `docker-compose.prod.yml` |
| Env file | `.env` from `.env.example` | A private file, for example `.env.staging` | `.env.prod` from the secret store |
| `APP_ENV` | `dev` | `prod` | `prod` |
| `APP_DEBUG` | `1` | `0` | `0` |
| Frontend | Vite dev server | `npm run build`, served by Nginx | `npm run build`, served by Nginx |
| Fingerprint and camera | Mock adapters | Unavailable until a vendor adapter is configured | Unavailable until a vendor adapter is configured |
| MySQL, Redis, RabbitMQ ports | Published on the workstation | Not published | Not published |
| TLS | HTTP on port 8080 | HTTPS at Nginx, or HTTP behind a TLS load balancer | HTTPS at Nginx, or HTTP behind a TLS load balancer |
| Data | Local Docker volumes | Staging volumes, never the production volumes | Production volumes and the backup directory |

The production boot refuses a debug flag, the secrets shipped for local development, a SQLite tenant driver, a Redis URL without a password, a Messenger transport that is not AMQP, and a `DEFAULT_URI` that does not start with `https://`. The exception names the variable. It does not include the value.

## Development

Follow `docs/development.md`. The worker uses `restart: unless-stopped`, so the process comes back after its time limit. That file is the local stack. It is not a staging or production deployment.

## Staging and production

Copy `.env.prod.example` to a file that is not committed. Fill every secret from the secret store. Use a different `APP_SECRET`, database password, RabbitMQ password, Redis password, and `TENANT_DB_CREDENTIALS_KEY` in staging and in production. The credentials key is 32 bytes, base64-encoded. Its key id must not be `dev` or `test`.

Passwords that are placed into `PLATFORM_DATABASE_URL`, `REDIS_URL`, and `MESSENGER_TRANSPORT_DSN` must be URL-encoded when they contain `@`, `:`, or `/`. Compose builds those URLs from `MYSQL_PASSWORD`, `REDIS_PASSWORD`, and `RABBITMQ_PASSWORD`.

`EXPLORER_PLATFORM_DATABASE_URL` and `EXPLORER_TENANT_DATABASE_URL` are separate read-only accounts for the database explorer. Leave them empty until those accounts exist. An empty value makes the explorer return `503`. Do not copy the application database user or password into either explorer URL. The tenant explorer user needs `SELECT` on the tenant schema only. Grant no insert, update, delete, or administrative privilege.

`TRUSTED_PROXIES=REMOTE_ADDR` is the production default. PHP-FPM is reached only by Nginx, so the application trusts the forwarding headers from that direct peer. Do not publish port 9000.

### TLS

`TLS_TERMINATION=nginx` is the default. Mount a certificate chain at `docker/nginx/certs/fullchain.pem` and the private key at `docker/nginx/certs/privkey.pem`. Port 80 redirects to HTTPS. The liveness and readiness paths stay on port 80 so a local probe can call them. Nginx speaks TLS 1.2 and TLS 1.3 and sends HSTS. The PHP upstream is resolved through Docker's DNS, so Nginx can reload while PHP restarts.

`TLS_TERMINATION=edge` is the staging or production layout where a load balancer already terminates TLS. Nginx listens on port 80 inside the private network and forwards `X-Forwarded-Proto`. Only the load balancer may connect to that port. The public URL in `DEFAULT_URI` is still `https://`.

### Release

From a host that has the env file and the Docker socket. Set `COMPOSE_PROJECT_NAME` so this stack does not replace the development containers on the same machine:

```sh
COMPOSE_PROJECT_NAME=attendance ENV_FILE=.env.prod docker/deploy/release.sh
```

To deploy a **pinned GHCR image** instead of building on the host:

```sh
COMPOSE_PROJECT_NAME=attendance \
  ENV_FILE=.env.prod \
  DEPLOY_MODE=pull \
  IMAGE_TAG=sha-<12-char-git-sha> \
  GHCR_OWNER=<lowercase-github-owner> \
  BACKUP_BEFORE_MIGRATE=1 \
  docker/deploy/release.sh
```

Staging uses the same script with `ENV_FILE` pointing at the staging file. The script refuses to continue unless `APP_ENV=prod` and `APP_DEBUG=0`. Continuous deployment wiring, GitHub variables/secrets, and the enablement checklist are in [ci-cd-deployment.md](ci-cd-deployment.md). No production host is configured in this repository yet.

The order is fixed:

1. Build the PHP and Nginx images (`DEPLOY_MODE=build`, default), or pull immutable GHCR tags (`DEPLOY_MODE=pull`).
2. Start MySQL, Redis, and RabbitMQ and wait until their health checks pass (`docker compose up --wait`).
3. Optional: `BACKUP_BEFORE_MIGRATE=1` runs `backup-databases.sh` before schema changes.
4. Stop the Messenger consumer, then `doctrine:migrations:migrate` on the platform database.
5. `app:tenant:migrate --all`, one tenant database at a time.
6. Start PHP-FPM, Nginx, and the Messenger consumer.
7. `app:diagnostics` must report `ok`, then HTTP liveness, readiness, and frontend probes.

A failed tenant migration stops the command. Databases that already migrated stay migrated. Fix the migration and run `app:tenant:migrate --all` again. Do not roll a production schema backward. Ship a forward migration.

`app:tenant:provision` creates one tenant database, encrypts its password with `TENANT_DB_CREDENTIALS_KEY`, runs the tenant migrations, and marks the tenant active. The command prints the tenant id. It does not print the database password. Provisioning is an operator action. It is not part of the web request.

### Workers

The consumer command is `messenger:consume async` with a time limit, a memory limit, and a message limit. Docker restarts the container when that process exits, including a clean exit at the time limit. `stop_grace_period` gives the current message time to finish on SIGTERM.

Before a message is handled, `TenantSwitchMiddleware` clears the tenant connection. It then opens the database named by the `TenantStamp` on a message the worker received. Dispatch during an HTTP request does not change the connection that request already selected. The connection is cleared again in a `finally` block, including when the handler or the switch throws. `WorkerTenantReset` clears it when the worker starts and after a handled or failed message. The next message cannot see the previous tenant.

Heartbeats stay on the HTTP request. Domain events are the queue workload. One consumer handles one message at a time. Add containers to scale.

### Health

| Probe | Endpoint or command | Ready when |
| --- | --- | --- |
| Liveness | `GET /api/v1/health` | The PHP process answers. No dependency is required |
| Readiness | `GET /api/v1/health/ready` and `php bin/console app:diagnostics` | Platform database, Redis, and RabbitMQ answer, and the tenant connection is unselected |

A selected tenant connection during readiness is a failure. Redis and RabbitMQ passwords are used for the check and are not written into the response. Nginx is healthy when liveness answers through the proxy.

### Logging and errors

Production PHP logs to stderr. `display_errors` is off. `zend.exception_ignore_args` is on, so stack traces do not include function arguments. Monolog writes JSON. `LOG_LEVEL` is the level that opens the production log buffer. The default is `error`, so a failed request emits the buffered context. Set `LOG_LEVEL=info` on a staging collector that wants the quieter application records as well.

Each record can carry `extra.request_id` and `extra.tenant_id`. `SecretRedactingProcessor` removes `user:password@` from the message and from string context before the line is written. Ship stderr to the log collector and alert on `"level":400` or `"level_name":"ERROR"`. The application does not embed a vendor error tracker. The JSON stream is the monitoring interface.

### Photographs

`MEDIA_LOCAL_DIRECTORY` is the private volume shared by PHP and the worker. Nginx has no route to it. Back up that volume with the databases. A restore puts the files back under the same directory because the database stores the media key, not a public URL. The later object-store adapter is described in `docs/media-storage.md`.

### Database backups

`docker/mysql/backup-databases.sh` runs inside the MySQL container. It writes a gzip dump of the platform database and of every tenant database whose connection row has no `dropped_at`. Names that are not simple identifiers are refused. The root password is placed in a mode `0600` client file and removed when the script exits. The script prints the UTC directory name and nothing else.

```sh
docker compose --env-file .env.prod -f docker-compose.prod.yml -p "${COMPOSE_PROJECT_NAME:-attendance}" exec -T mysql /usr/local/bin/backup-databases.sh
```

Schedule that command at least daily. Keep the dumps, and a copy of `TENANT_DB_CREDENTIALS_KEY`, in the secret store's backup. The SQL dump contains the encrypted tenant passwords. It does not contain the key that decrypts them. Store the key apart from the dump. Retain enough days to restore yesterday and the day before. A tenant database that has been dropped is absent from the next dump; keep the earlier dump if that database must still be recoverable.

Restore stops the worker first, restores the platform database, restores each tenant database, restores the photograph volume, then starts PHP and the worker. Run `app:diagnostics` and `app:tenant:check` before opening traffic.

### Rollback

Stop the Messenger consumer first so a new domain event is not handled by an old release. Deploy the previous **immutable** image tag (do not use `latest`). Leave the schema where the forward migration put it. If the release depended on a schema change, ship a forward fix. Do not run a down migration against production.

```sh
COMPOSE_PROJECT_NAME=attendance \
  ENV_FILE=.env.prod \
  IMAGE_TAG=sha-<previous> \
  GHCR_OWNER=<lowercase-github-owner> \
  docker/deploy/rollback.sh
```

## Configuration reference

| Variable | Production rule |
| --- | --- |
| `APP_ENV` | `prod` |
| `APP_DEBUG` | `0` |
| `APP_SECRET` | At least 32 characters, supplied by the secret store |
| `DEFAULT_URI` | Public `https://` origin |
| `PLATFORM_HOSTNAME` | Host that resolves to the platform, not a tenant |
| `TRUSTED_PROXIES` | `REMOTE_ADDR` when Nginx is the only proxy in front of PHP |
| `LOG_LEVEL` | `error` in production. `info` when staging wants a fuller stream |
| `MYSQL_ROOT_PASSWORD`, `MYSQL_USER`, `MYSQL_PASSWORD` | Secret store. Not the local example values |
| `REDIS_PASSWORD` | Required. Redis refuses unauthenticated clients |
| `RABBITMQ_USER`, `RABBITMQ_PASSWORD` | Secret store. The management UI is not published |
| `TENANT_DB_CREDENTIALS_KEY` | 32 bytes, base64. Not the local development key |
| `TENANT_DB_CREDENTIALS_KEY_ID` | Name of that key. Not `dev` or `test` |
| `TENANT_DATABASE_DRIVER` | `mysql` |
| `MEDIA_LOCAL_DIRECTORY` | Private volume path |
| `TLS_TERMINATION` | `nginx` or `edge` |
| `MESSENGER_TIME_LIMIT`, `MESSENGER_MEMORY_LIMIT`, `MESSENGER_MESSAGE_LIMIT` | Worker recycle limits |

The local values in `.env.example` and `backend/.env` exist for the development stack. The production image deletes `backend/.env` so a missing environment variable cannot fall back to them.
