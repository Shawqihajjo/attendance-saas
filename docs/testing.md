# Automated test strategy

Tests run without a fingerprint reader, a camera, Redis, or RabbitMQ. Backend tests use SQLite. Frontend tests use Vitest and Testing Library. A failed test stops the change.

```sh
docker compose exec php php vendor/bin/phpunit
docker compose exec php php vendor/bin/phpstan analyse --memory-limit=512M
docker compose exec frontend npm test
docker compose exec frontend npm run lint
docker compose exec frontend npx tsc -b --pretty false
```

The same checks run on GitHub Actions (`.github/workflows/ci.yml`). See [ci.md](ci.md).

PHPStan includes `src` and `tests`. The frontend `npm test` script runs Vitest once.

## Layers

| Layer | Location | What it proves |
| --- | --- | --- |
| Unit | `backend/tests/Unit` | State machine, UTC and DST math, signature checks, hardware faults, audit redaction, tenant input, message stamps, production configuration, worker tenant reset |
| Integration | `backend/tests/Integration` | Ledger commits, report time, tenant databases, domain-event isolation, query shape |
| API | `backend/tests/Api` | HTTP status, problem codes, and response bodies for terminal, HR, and platform routes |
| Authorization | `AuthorizationApiTest`, `AccessCatalogTest`, `frontend/src/app/access.test.ts` | Server permissions and the routes each permission may show |
| Tenant isolation | `TenantIsolationTest`, `DomainEventIsolationTest`, `TenantConfigurationApiTest` | One tenant cannot read or write another tenant's database, session, or event |
| Frontend components | `Button.test.tsx`, `KioskPage.test.tsx` | Rendered controls and the kiosk result |
| Redux | `sessionSlice.test.ts` | Session status, roles, and permissions come from the server payload |
| Terminal state | `terminalMachine.test.ts`, `hardware.test.ts` | Steps, retries, and adapter faults |
| Theme and language | `branding.test.ts`, `format.test.ts`, `locale.test.ts` | Tenant colors, displayed time, English and Swedish copy |

The manager role is the chief of an assigned team. There is no separate chief role. A manager sees assigned employees and the team report scope. HR permissions are required for the HR area.

## Critical scenarios

| Scenario | Automated proof |
| --- | --- |
| 1. Employee clocks in | `TerminalApiTest` commits `clock_in` and reaches `working`. `AttendanceLedgerTest` stores the event. |
| 2. Employee clocks out | The same API test commits `clock_out` and reaches `off`. |
| 3. Employee starts lunch | The API test commits `lunch_start` and reaches `on_lunch`. The state machine and the ledger accept that transition. |
| 4. Employee ends lunch | The API test commits `lunch_end` and returns to `working`. |
| 5. Invalid transitions fail | `AttendanceStateMachineTest` rejects every illegal pair. The API test rejects `clock_out` while `off` with `illegal_transition`. The ledger rejects a second lunch start and a clock-out during lunch. |
| 6. Duplicate requests | The API replay of the same attempt and idempotency key returns `replayed: true` and the original event id. `AttendanceLedgerTest` keeps one row and rejects the same key used for a different action. The kiosk reuses one idempotency key per attempt and action. |
| 7. Unknown employee code | The API returns `422` `unknown_code` and the response does not contain the submitted code. The kiosk shows the same message as an inactive employee and does not retry into attendance. |
| 8. Fingerprint mismatch | The API returns `fingerprint_mismatch` for `dev-reject`. `HardwareAdapterTest` and `DeviceSignatureTest` reject other samples. The kiosk retries fingerprint verification. |
| 9. Camera failure | A non-image upload returns `photo_invalid` and the attempt can still store a valid JPEG afterward. `MockCameraAdapter` reports `capture_failed` without putting image bytes in the exception. The kiosk retries the photo step. |
| 10. Offline terminal | A network failure presents `kiosk.reason.network`, retries the same step, and keeps the idempotency key. English and Swedish copy say nothing was recorded. The server still requires a current device signature, so a terminal cannot commit attendance while it cannot reach the API. |
| 11. Disabled terminal | A suspended terminal receives `403` `device_inactive` for a heartbeat and for an attendance attempt. |
| 12. Revoked terminal | A revoked terminal receives the same `device_inactive` rejection, and activation is refused. |
| 13. Tenant A cannot access Tenant B | `TenantIsolationTest` uses two databases. `AuthorizationApiTest` rejects a session cookie on the other host. Report reads on the other host do not contain the first tenant's instants. |
| 14. Tenant A terminal cannot access Tenant B | `TerminalApiTest` sends tenant A's device signature to tenant B's host and receives `403` `tenant_mismatch`. |
| 15. UTC is stored | `UtcTimeTest`, `AttendanceLedgerTest`, and `AttendanceTimeReportTest` store the UTC civil time. The API `occurredAt` ends in `Z` and ignores a client-supplied `1999-01-01T00:00:00Z`. |
| 16. Timezone conversions | `TimezoneApiTest` and `AttendanceTimeReportTest` present Stockholm, Chicago, Auckland, New York, and Tokyo from the same UTC instant. |
| 17. DST | Spring and autumn Europe/Stockholm examples in `UtcTimeTest`, `AttendanceStateMachineTest`, `AttendanceLedgerTest`, and `AttendanceDaySummaryTest` keep a one-hour timestamp duration when the displayed clock jumps. |
| 18. HR can perform authorized operations | `AuthorizationApiTest` lets HR list employees, decide a correction, create a department and a schedule, and read the tenant report scope. |
| 19. Employee cannot access HR data | The same test returns `403` for employees, users, audit, reports, attendance review, correction decisions, and tenant settings. |
| 20. Chief sees only permitted teams | The manager role is that chief. `AuthorizationApiTest` lists only the assigned employee. `AttendanceReportApiTest` gives the manager `scope: team`, an empty report for unassigned employees, and a dashboard that omits those employees. |
| 21. Audit logs are created | Sign-in, attendance commit, correction, and audit-page views write audit rows. `SecurityControlsApiTest` and `AuthorizationApiTest` read them back. |
| 22. Secrets are not logged | `AuditMetadataTest` strips passwords, tokens, keys, photographs, and samples. The ledger drops an attendance code from event metadata. Hardware logs drop biometric payloads. API responses omit the attendance code, storage path, and password. `SecretRedactingProcessor` removes userinfo from log messages before they are written. |

## Boundaries

Production configuration selects `UnavailableFingerprintReader` when `APP_ENV=prod`. Tests use `MockFingerprintAdapter` and `MockCameraAdapter`. A vendor SDK is not required.

Tenant resolution is exercised through the host header. Tests do not cache a tenant context across a suspension.

Domain-event tests publish through the test transport and assert the stamped tenant. They do not require a running RabbitMQ broker.

Load, browser end-to-end, and physical-device certification sit outside this suite. The API and kiosk tests are the contract for the attendance workflow.
