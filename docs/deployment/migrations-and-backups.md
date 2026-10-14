# Migrations and backups

## Migration strategy (controlled)

Migrations are **not** applied on every PHP-FPM start. Operators (or `docker/deploy/release.sh`) run them explicitly.

### Order

1. Optional backup (`BACKUP_BEFORE_MIGRATE=1` → `docker/mysql/backup-databases.sh`)  
2. Stop Messenger consumer  
3. **Platform:** `doctrine:migrations:migrate --no-interaction --env=prod`  
4. **Tenants:** `app:tenant:migrate --all` (one tenant at a time; first failure aborts; overall success is not claimed if any tenant fails)  
5. Start PHP, Nginx, Messenger  
6. `app:diagnostics` + HTTP probes  

Local equivalents:

```sh
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console app:tenant:migrate --all
```

Paths: `backend/migrations/platform/`, `backend/migrations/tenant/`.

### Isolation

Each tenant migration switches for maintenance, migrates, then resets the tenant connection so the next tenant cannot see the previous connection.

## Backups

On a production Compose stack:

```sh
docker compose --env-file .env.prod -f docker-compose.prod.yml -p "${COMPOSE_PROJECT_NAME:-attendance}" \
  exec -T mysql /usr/local/bin/backup-databases.sh
```

The script dumps the platform database and every tenant database with `dropped_at IS NULL`, gzip-compressed under the `backups` volume. It prints the UTC stamp directory name only.

**Keep `TENANT_DB_CREDENTIALS_KEY` outside the dump.** Dumps contain encrypted tenant passwords, not the key.

Schedule at least daily. Retain enough history to restore yesterday and the day before. Also back up the photograph volume (`media_data`).

## Restore (summary)

1. Stop the worker  
2. Restore platform DB, then each tenant DB  
3. Restore photograph volume to the same paths referenced by storage keys  
4. Start PHP and worker  
5. Run `app:diagnostics` and `app:tenant:check` before opening traffic  

Full narrative: [../deployment.md](../deployment.md).

## Destructive commands (explicit warnings)

| Command | Risk |
| --- | --- |
| `docker compose down -v` | Deletes local volumes including MySQL data |
| `app:tenant:drop-database … --confirm` | Drops that tenant’s database permanently |
| Doctrine **down** migrations | **Not** recommended for production tenant data |

Never run destructive resets against production without an approved restore plan.
