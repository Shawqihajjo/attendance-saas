# Production deployment

This document summarizes **supported** production layout. Full operational detail: [../deployment.md](../deployment.md). CD wiring: [../ci-cd-deployment.md](../ci-cd-deployment.md).

## Status

| Claim | Reality |
| --- | --- |
| Production Compose file | **Yes** — `docker-compose.prod.yml` |
| Release script | **Yes** — `docker/deploy/release.sh` |
| Automated CD to a live host | **Not configured** until GitHub deploy variables/secrets and a real server exist |
| Production fingerprint vendor | **Not connected** |

Do not claim a live production deploy succeeded unless you observed it.

## Architecture

- Images: `attendance-php` (`Dockerfile.prod`), `attendance-nginx` (React production build)  
- Services: nginx, php, messenger, mysql, redis, rabbitmq  
- Secrets: private env file from `.env.prod.example` on the host — **never** baked into images  
- TLS: `TLS_TERMINATION=nginx` (certs mounted) or `edge` (TLS at load balancer)  

## High-level steps (on a prepared host)

1. Install Docker Compose v2  
2. Clone repository; create private `.env.prod` from `.env.prod.example`  
3. Place TLS material if using Nginx termination  
4. Either build on host or pull pinned GHCR tags (`DEPLOY_MODE=pull`, `IMAGE_TAG=sha-…`)  
5. Run:

```sh
COMPOSE_PROJECT_NAME=attendance \
  ENV_FILE=.env.prod \
  BACKUP_BEFORE_MIGRATE=1 \
  docker/deploy/release.sh
```

6. Confirm `app:diagnostics` and HTTP `/api/v1/health` + `/api/v1/health/ready`  

## Workers

Messenger consumer runs in the `messenger` service with time/memory/message limits and Docker restart. Stop the worker before risky schema work (the release script does this).

## Logging

PHP logs JSON to stderr; secrets are redacted. Ship stderr to your collector. No embedded third-party APM is required by the codebase.

## Upgrades

1. Publish/pull new immutable image tags  
2. Backup  
3. Run `release.sh` (migrations + recreate app containers)  
4. Health-check  

## Rollback

```sh
COMPOSE_PROJECT_NAME=attendance \
  ENV_FILE=.env.prod \
  IMAGE_TAG=sha-<previous> \
  GHCR_OWNER=<lowercase-owner> \
  docker/deploy/rollback.sh
```

Do not Doctrine-down migrate production tenant data. Prefer a forward fix if schema already moved.

## Security

- Distinct secrets for staging vs production  
- Explorer URLs must be read-only accounts or empty  
- Do not reuse `.env.example` values  
