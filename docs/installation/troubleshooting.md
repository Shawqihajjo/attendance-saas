# Troubleshooting

Practical fixes for the **actual** Docker + Symfony + React stack. Prefer collecting diagnostics without copying secrets, attendance photographs, or biometric samples into chat logs.

## Collect diagnostics (safe)

```sh
docker compose ps
curl -sS http://localhost:8080/api/v1/health
curl -sS http://localhost:8080/api/v1/health/ready
docker compose exec php php bin/console app:diagnostics
docker compose logs --tail=100 nginx php messenger mysql redis rabbitmq frontend
```

Redact passwords from any pasted `PLATFORM_DATABASE_URL` or similar.

---

## Docker containers fail to start

1. Confirm Docker is running: `docker info`
2. Rebuild: `docker compose up --build`
3. Read the first failing service in `docker compose ps` / logs
4. PHP entrypoint needs `backend/composer.json` mounted at `/var/www/html`

## Port conflicts

Development publishes `8080`, `3306`, `6379`, `5672`, `15672`. If something else binds them, stop the other process or change the published ports in `docker-compose.yml` (only if you understand the knock-on effects for `DEFAULT_URI` and docs).

## Database connection failures

- Wait until MySQL is `healthy`
- Confirm `.env` `MYSQL_*` match what Compose injects
- Run `app:diagnostics`
- Ensure you ran `doctrine:migrations:migrate` at least once on a fresh volume

## Failed migrations

- Platform: re-run `doctrine:migrations:migrate --no-interaction` and read the SQL error
- Tenant: `app:tenant:migrate --slug=…` stops on failure; fix forward; re-run `--all` if needed
- **Do not** run Doctrine down migrations against valuable data

## Tenant provisioning errors

- Check `TENANT_DB_CREDENTIALS_KEY` is valid base64 32-byte material
- Root admin URL must work (`MYSQL_ROOT_PASSWORD`)
- Failed provision may leave status `provisioning`; retry migrate/activate paths described in [../tenant-databases.md](../tenant-databases.md)
- Inspect platform audit (API) for `tenant.provision_failed` **without** expecting passwords in the log

## Redis / RabbitMQ failures

- Readiness / diagnostics show each check
- Development Redis has no password; production **requires** `REDIS_PASSWORD`
- Messenger needs AMQP DSN; worker container must be running for async domain events

## Symfony / PHP issues

- Missing `vendor`: `docker compose exec php composer install`
- Cache: `docker compose exec php php bin/console cache:clear` (dev)
- PHPUnit needs a real `backend/.env` file for Dotenv bootstrap even though `phpunit.dist.xml` forces test env vars: `docker compose exec php sh -c 'test -f .env || cp .env.test .env'`

## Frontend build / Vite errors

```sh
docker compose logs frontend
docker compose exec frontend npm ci
docker compose exec frontend npm run lint
```

Ensure Nginx can reach the frontend service (Compose `depends_on`).

## Login problems

| Symptom | Likely cause |
| --- | --- |
| Cannot sign in on localhost as tenant user | Use tenant host (`acme.localhost`) for tenant login |
| Cannot sign in as platform admin on tenant host | Use platform host (`localhost`) |
| 403 csrf_rejected | Browser `Origin` rejected |
| 429 rate_limited | Too many failures; wait / use correct password |
| No accounts work | You must create users with CLI — no default password in git |

## Incorrect permissions

UI hides links by permission; API still enforces voters. Confirm `GET /api/v1/auth/session` permissions. Assign roles with `app:tenant:create-user … --role=` or tenant user management UI (`users.manage`).

## Attendance transition failures

Legal transitions only: `off` → `working` → `on_lunch` → `working` → `off` via `clock_in`, `lunch_start`, `lunch_end`, `clock_out`. Server clock is authoritative. See [../architecture/attendance-workflow.md](../architecture/attendance-workflow.md).

## Terminal authentication failures

- Device must be enrolled, `active`, and bound to the tenant host
- Signature headers required on `/api/v1/terminal/*`
- Heartbeats expected; policies may reject IP/network/app version
- Development mocks accept only the documented mock fingerprint sample

## Fingerprint / camera

| Environment | Behavior |
| --- | --- |
| Development without `window.attendanceHardware` | Mock fingerprint + mock camera |
| Production | Unavailable adapters until a vendor is integrated |

Missing vendor SDK is **expected** in production today — not a misconfiguration of `.env`.

## Wrong displayed timezone

Storage is UTC. Display uses tenant IANA timezone (and report/browser/terminal display options on reports). Fixed offsets are rejected. Verify tenant settings at `/settings/timezone`.

## Failed CI workflows

- Open the GitHub Actions run → failed job → failed step
- Backend PHPUnit historically failed when `.env` was missing; CI copies `.env.test` → `.env`
- Re-run after fixing; see [../development/github-and-ci-cd.md](../development/github-and-ci-cd.md)

## Failed deployment / health checks

Deploy is **inactive** until hosts and `DEPLOY_ENABLED` are configured ([../ci-cd-deployment.md](../ci-cd-deployment.md)). On a configured host, `docker/deploy/release.sh` fails if `app:diagnostics` or HTTP probes fail — fix dependencies before forcing traffic.

## Still stuck?

1. Capture readiness JSON and `app:diagnostics` (redacted)
2. Note tenant slug and whether you used platform vs tenant host
3. Avoid sharing `.env`, SQL dumps, or photo bytes
