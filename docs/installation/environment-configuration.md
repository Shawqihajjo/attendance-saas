# Environment configuration

Environment values come from Compose env files and from variables Compose injects into containers. **Never commit** real `.env`, `.env.prod`, or `.env.staging` files.

Templates in the repository:

| File | Use |
| --- | --- |
| `.env.example` | Local development → copy to `.env` |
| `.env.prod.example` | Staging/production checklist → copy to a **private** file (for example `.env.prod`) |

Production rules and refusal checks: [../deployment.md](../deployment.md).

## Frontend vs server secrets

| Kind | Visibility |
| --- | --- |
| Root `.env` / Compose PHP environment | **Server-side only** (containers). Not shipped to the browser as a Vite `VITE_*` secret map. |
| Browser client | Calls same-origin `/api/v1`. Dev frontend uses `VITE_API_PROXY=http://nginx` inside Compose for the Vite proxy — not an API key. |

There is **no JWT** configuration in this project. Authentication uses an HTTP-only session cookie named `ATTENDANCE_SESSION` with the session payload stored in Redis.

---

## Local development (`.env` from `.env.example`)

| Variable | Required | Purpose | Safe example / note | Consumed by |
| --- | --- | --- | --- | --- |
| `APP_SECRET` | Yes | Symfony application secret | Placeholder `dev-only-change-me` — **dev only** | `php`, `messenger` |
| `PLATFORM_HOSTNAME` | Yes | Host treated as platform (not a tenant) | `localhost` | `php`, `messenger` |
| `MYSQL_ROOT_PASSWORD` | Yes | MySQL root (provisioning admin URL) | Local placeholder in `.env.example` | `mysql`, `TENANT_DATABASE_ADMIN_URL` |
| `MYSQL_DATABASE` | Yes | Platform database name | `platform` | `mysql`, Compose URLs |
| `MYSQL_USER` | Yes | Application MySQL user | Local placeholder | `mysql`, Compose URLs |
| `MYSQL_PASSWORD` | Yes | Application MySQL password | Local placeholder | `mysql`, Compose URLs |
| `RABBITMQ_USER` | Yes | Broker user | Local placeholder | `rabbitmq`, Messenger DSN |
| `RABBITMQ_PASSWORD` | Yes | Broker password | Local placeholder | `rabbitmq`, Messenger DSN |
| `TENANT_DB_CREDENTIALS_KEY` | Yes | Encrypts tenant DB passwords in the platform registry (32 bytes, base64) | Example in `.env.example`; generate with `openssl rand -base64 32` for shared envs | `php`, `messenger` |
| `TENANT_DB_CREDENTIALS_KEY_ID` | Yes | Name of that key | `dev` **only** for local | `php`, `messenger` |
| `EXPLORER_PLATFORM_DATABASE_URL` | Optional | SELECT-only account for Database Explorer (platform) | Leave empty until created | Symfony when set |
| `EXPLORER_TENANT_DATABASE_URL` | Optional | SELECT-only account for explorer (tenant DBs) | Leave empty until created | Symfony when set |

### Built by Compose for PHP (not usually set by hand in `.env`)

| Variable | How it is formed (development) |
| --- | --- |
| `PLATFORM_DATABASE_URL` | `mysql://…@mysql:3306/${MYSQL_DATABASE}` |
| `TENANT_DATABASE_URL` | Boot placeholder DB `tenant_placeholder` — **not** a real tenant |
| `REDIS_URL` | `redis://redis:6379` (no password in development Compose) |
| `MESSENGER_TRANSPORT_DSN` | `amqp://…@rabbitmq:5672/%2f` |
| `TENANT_DATABASE_ADMIN_URL` | `mysql://root:…@mysql:3306` for provisioning |
| `DEFAULT_URI` | `http://localhost:8080` |
| `APP_ENV` / `APP_DEBUG` | `dev` / `1` in development Compose |

Also set in Compose: `TENANT_DATABASE_DRIVER=mysql`, host `mysql`, port `3306`, server version `8.4`.

---

## Staging and production (from `.env.prod.example`)

All blanks must be filled from a secret store. Production boot **refuses** debug mode, known local secrets, SQLite tenants, Redis without a password, non-AMQP Messenger, and non-HTTPS `DEFAULT_URI` (see [../deployment.md](../deployment.md)).

| Variable | Required | Purpose | Notes |
| --- | --- | --- | --- |
| `APP_ENV` | Yes | Must be `prod` | |
| `APP_DEBUG` | Yes | Must be `0` | |
| `APP_SECRET` | Yes | ≥ 32 characters from secret store | Not the local example |
| `TRUSTED_PROXIES` | Yes | Default `REMOTE_ADDR` when Nginx is the only proxy to PHP | |
| `DEFAULT_URI` | Yes | Public `https://…` origin | |
| `PLATFORM_HOSTNAME` | Yes | Platform hostname | |
| `LOG_LEVEL` | Optional | Default `error` | `info` useful on staging collectors |
| `MYSQL_ROOT_PASSWORD` | Yes | Root / admin for provisioning | |
| `MYSQL_DATABASE` | Yes | Platform DB name | Default `platform` |
| `MYSQL_USER` / `MYSQL_PASSWORD` | Yes | Application DB user | URL-encode special characters if you embed them elsewhere |
| `REDIS_PASSWORD` | Yes | Redis `requirepass` | Required in production Compose |
| `RABBITMQ_USER` / `RABBITMQ_PASSWORD` | Yes | Broker | Management UI not published in prod Compose |
| `TENANT_DB_CREDENTIALS_KEY` | Yes | Base64 32-byte key | Not the local key |
| `TENANT_DB_CREDENTIALS_KEY_ID` | Yes | Key id | **Not** `dev` or `test` |
| `TENANT_DATABASE_DRIVER` | Yes | `mysql` | |
| `TENANT_DATABASE_HOST` / `_PORT` / `_USER_HOST` / `_SERVER_VERSION` | Yes | Tenant DB connection template | Defaults match Compose MySQL |
| `MEDIA_LOCAL_DIRECTORY` | Yes | Private photo path in container | Default `/var/www/html/var/photos` |
| `EXPLORER_*_DATABASE_URL` | Optional | Read-only explorer | Empty → explorer returns **503** |
| `TLS_TERMINATION` | Yes | `nginx` or `edge` | |
| `HTTP_PORT` / `HTTPS_PORT` | Optional | Published ports | Defaults 80 / 443 |
| `TLS_CERT_DIR` | When `TLS_TERMINATION=nginx` | Host path to certs | `fullchain.pem`, `privkey.pem` |
| `MESSENGER_TIME_LIMIT` / `_MEMORY_LIMIT` / `_MESSAGE_LIMIT` | Optional | Worker recycle limits | Defaults in example file |

### Image overrides (production Compose)

| Variable | Purpose |
| --- | --- |
| `ATTENDANCE_PHP_IMAGE` | Pin GHCR PHP image (for example `ghcr.io/<owner>/attendance-php:sha-…`) |
| `ATTENDANCE_NGINX_IMAGE` | Pin GHCR Nginx image |

---

## Email and external SaaS

There is **no** SMTP / SendGrid / Mailgun environment configuration for outbound invitation email in the application env templates. Integration outbox rows may exist for future delivery; do not document live email sending as implemented.

Fingerprint **vendor** SDKs are not configured via env vars in this repository. Development uses mock adapters; production uses unavailable adapters until a vendor is integrated ([../architecture/devices-and-hardware.md](../architecture/devices-and-hardware.md)).

## Generating a tenant credentials key

```sh
openssl rand -base64 32
```

Store the value as `TENANT_DB_CREDENTIALS_KEY` and give it a non-`dev` id in shared environments. Keep the key **separate** from SQL backups (dumps contain ciphertext only).
