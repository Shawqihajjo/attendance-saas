# Coding conventions

Conventions below are inferred from **this repository’s tooling and layout**, not from a separate style-guide product.

## Backend (PHP / Symfony)

| Topic | Practice in this repo |
| --- | --- |
| PHP | 8.4 in Docker/CI; `composer.json` allows `>=8.2` |
| Framework | Symfony 7.4 |
| Static analysis | PHPStan via `backend/phpstan.neon` (`composer phpstan` / CI) |
| Tests | PHPUnit; fail on deprecation/notice/warning (`phpunit.dist.xml`) |
| Structure | Domain-oriented folders under `backend/src/` (`Platform`, `Tenant`, `Identity`, …) |
| Tenancy | Never trust client `tenant_id`; reset tenant connections after use |
| Secrets | No production secrets in git; redaction in logs (`SecretRedactingProcessor`) |
| Migrations | Platform: `backend/migrations/platform`; Tenant: `backend/migrations/tenant` |

Run PHPStan and PHPUnit before opening a pull request ([testing.md](testing.md)).

## Frontend (React / TypeScript)

| Topic | Practice in this repo |
| --- | --- |
| Bundler | Vite |
| State | Redux Toolkit for session/branding/kiosk |
| Routing | React Router; guards use **permission strings** from the session |
| Lint | ESLint (`frontend/eslint.config.js`) — `npm run lint` |
| Types | `tsc -b` as part of `npm run build` and CI |
| i18n | i18next catalogs `en` / `sv` |

Do not treat UI route visibility as authorization — the API enforces permissions.

## Commits and secrets

- Do not commit `.env`, `.env.prod`, TLS keys, SQL dumps, or `var/photos`
- Prefer small, focused commits that keep CI green

## Further design rules

Long-form decisions: [../architecture.md](../architecture.md).
