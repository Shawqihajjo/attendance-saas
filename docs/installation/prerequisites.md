# Prerequisites

This project is designed to run primarily through **Docker Compose**. A host install of PHP or Node.js is optional and not required for the default local workflow.

## Supported local workflow

| Requirement | Notes |
| --- | --- |
| Git | Clone the repository |
| Docker Engine **or** Docker Desktop | Provides containers for PHP, Nginx, MySQL, Redis, RabbitMQ, and the Vite frontend |
| Docker Compose **v2** | Invoked as `docker compose` (space, not hyphen) |
| `curl` (recommended) | Used in the install guide to probe `/api/v1/health` and `/api/v1/health/ready` |

**Verified in this repository’s documentation and Compose files:** development uses `docker-compose.yml`; staging/production use `docker-compose.prod.yml`.

## Versions used by the stack

These versions come from Compose images and CI, not from inventing a matrix:

| Component | Version in project |
| --- | --- |
| PHP (dev image / CI) | **8.4** (`docker/php`, `.github/workflows/ci.yml`) |
| Symfony | **7.4** (`backend/composer.json`) |
| Node.js (frontend container / CI) | **22** (`node:22-alpine`, CI `setup-node`) |
| MySQL | **8.4** |
| Redis | **7-alpine** |
| RabbitMQ | **4** (dev: `rabbitmq:4-management-alpine`; prod: `rabbitmq:4-alpine`) |
| Nginx | **1.28-alpine** |

`backend/composer.json` declares `"php": ">=8.2"`. The runnable Docker and CI path uses **8.4**. Prefer matching that when running PHP on the host.

## Optional host toolchains

Only needed if you choose **not** to use the Compose PHP/frontend containers for day-to-day work:

| Tool | When |
| --- | --- |
| PHP 8.4 + extensions used by the app | Running `bin/console` or PHPUnit on the host |
| Composer 2 | Host `composer install` |
| Node.js 22 + npm | Host `npm ci` / Vitest / Vite |

Default recommendation: stay inside Docker (`docker compose exec …`).

## Hardware and OS

- **OS:** Any OS that runs Docker Desktop or Docker Engine + Compose v2 (macOS, Linux, Windows with Docker Desktop are the usual targets). The repository does not ship OS-specific installers.
- **Disk:** Space for container images plus the `mysql_data` Docker volume.
- **Ports free on the host (development):**
  - `8080` — application (Nginx)
  - `3306` — MySQL (published in development only)
  - `6379` — Redis (development only)
  - `5672` / `15672` — RabbitMQ AMQP / management UI (development only)

Production Compose does **not** publish MySQL, Redis, or RabbitMQ ports.

## Tenant hostnames on a laptop

Tenant UIs are resolved from the HTTP `Host` header (for example `acme.localhost`). Many systems resolve `*.localhost` to `127.0.0.1` automatically. If yours does not, add a hosts entry for each tenant hostname you create.

## Accounts and secrets

- There are **no default login passwords** in the repository.
- Local placeholders live in `.env.example` and are for **development only**.
- You create the first platform and tenant administrators with CLI commands after the stack is up (see [local-development.md](local-development.md)).

## Next step

Continue with [local-development.md](local-development.md).
