# Full application audit

Date: 2026-10-13

Scope: read-only audit of the attendance application in this workspace. No application source, configuration, dependencies, schemas, or live tenant data were changed for this audit.

Environment fact: this workspace is not a Git repository (`git status` returns `fatal: not a git repository`). Uncommitted changes cannot be listed. Nothing was reverted.

## A. Executive Summary

The automated contract is in good shape. PHPUnit, Vitest, ESLint, and TypeScript checking passed on an isolated SQLite configuration that does not use the live MySQL tenant databases. The attendance cycle, tenant isolation, quotas, device trust, reports, corrections, and the database explorer are covered by those tests.

This is not a production-readiness certificate. Signed-in product pages were not driven in the browser. Physical fingerprint hardware was not connected. Live tenant databases (`acme`, `beta`, `shawqi`) were not written. PHPStan level 6 fails with six findings in three files. Those findings are redundant runtime checks, not a reproduced attendance or isolation failure.

| Count | Value |
| --- | --- |
| Features audited | 27 |
| Passing | 15 |
| Failing | 0 |
| Partially working | 5 |
| Not implemented | 3 |
| Blocked | 1 |
| Not tested | 3 |
| Critical findings | 0 |
| High findings | 0 |
| Medium findings | 0 |
| Low findings | 1 |
| Info findings | 2 |

Major release blockers: none confirmed by a reproduced defect. The gaps that still block a production claim are unverified live MySQL workflows, the unsigned-only visual pass, and the absence of a vendor biometric adapter in production.

## B. Application Inventory

### Stack

- Backend: Symfony, PHP 8.4.26, Doctrine DBAL/ORM, PHPUnit 13.4.1, PHPStan level 6.
- Frontend: React, Redux Toolkit, RTK Query, React Router, i18next (English and Swedish), Vitest 5, ESLint, TypeScript.
- Local runtime: Docker Compose, MySQL for the running app, Redis and Messenger available in Compose. Tests force SQLite, an in-memory messenger transport, and a disabled Redis URL (`backend/phpunit.dist.xml`, `force="true"`).

### Backend modules

`Audit`, `Diagnostics`, `Foundation`, `Hardware`, `Identity`, `Integration`, `Media`, `Platform`, `Security`, `Shared`, `Tenant`, `TenantContext`.

### Frontend areas

`admin`, `branding`, `devices`, `diagnostics`, `kiosk`, `people`, `platform`, `session`, `workspace`.

Routes in `frontend/src/app/router.tsx` include `/login`, `/me`, `/people`, `/terminals`, `/team`, `/hr`, `/settings`, `/platform`, `/platform/databases`, `/platform/new`, `/platform/:slug`, and `/kiosk`.

### Roles

`employee`, `manager` (this is the chief role), `hr`, `tenant_admin`, `platform_admin`. There is no separate `SUPER_ADMIN` role. Platform administration is `platform_admin` in `backend/src/Identity/Domain/AccessCatalog.php`.

### Data stores

- Platform registry database.
- One database per tenant. MySQL names follow `tenant_` plus 32 hex characters. Tests use SQLite files.
- Explorer connections are separate read-only URLs. Empty or copied application credentials fail closed.

### Queues and integrations

Domain events are tenant-stamped. Tests use the in-memory transport and `RecordingWebhookClient`. Production fingerprint and camera services alias to unavailable adapters (`backend/config/services.yaml`). Dev and test alias the mocks.

### Test suites

42 PHPUnit classes under `backend/tests` (Unit, Integration, Api). 21 Vitest files. No browser end-to-end or load-test suite is configured. `docs/testing.md` states that load, browser end-to-end, and physical-device certification sit outside the suite.

## C. Feature-by-Feature Results

Statuses describe the best evidence collected in this audit. A passing automated test is not treated as proof of the live MySQL browser or of physical hardware.

| Feature | Backend | Frontend | End to end | Status | Evidence and limits |
| --- | --- | --- | --- | --- | --- |
| Platform and tenant databases, connection switch | PASS | n/a | SQLite only | PASS | `TenantAwareConnectionTest`, `TenantIsolationTest`. Live MySQL was not switched during this audit. |
| Host resolution; client tenant claims rejected | PASS | n/a | API | PASS | `TenantIsolationTest::testHostResolvesTheTenantAndClientClaimsCannotSwitchIt`. |
| Messenger tenant reset between messages | PASS | n/a | In-memory transport | PASS | `WorkerTenantResetTest`, `TenantSwitchMiddlewareTest`, `TenantStampDispatchMiddlewareTest`. RabbitMQ was not running as the test transport. |
| Tenant list, search, create, uniqueness, edit | PASS | Component tests | API on SQLite | PASS | `PlatformTenantManagementApiTest`, `platformPages.test.tsx`, `tenantRules.test.ts`. |
| Activate, suspend, reactivate, archive | PASS | Component tests ask before suspend | API on SQLite | PASS | `testLifecycleKeepsDataUntilASeparateConfirmedDrop`. Suspend does not drop the database in that test. |
| Permanent database drop | PASS | Typed slug confirmation in component test | Not run on live tenants | PASS | Confirmation must equal the slug (`PlatformTenantAdministration.php` lines 282–286). Live drop was not executed. |
| Provisioning failure and retry | PASS | Not driven in the browser | SQLite, with status forced in the test | PASS | `testFailedProvisioningStaysIncompleteUntilARetrySucceeds`. The MySQL provisioner class is not the test double. |
| Quotas: users, employees, devices, unlimited, boundary, overlap | PASS | Usage page and quota cards tested | API, including two overlapping creates | PASS | `TenantQuotaApiTest`, `QuotaUsageTest`. `testOverlappingEmployeeCreatesCannotShareTheLastSlot` expects exactly one created employee. |
| Database explorer and SQL console | PASS | Component tests | SQLite API | PARTIAL | Explorer tests passed. The signed-in live page was not opened after read-only accounts were configured. |
| Login, logout, roles, object scope, rate limit | PASS | Shell hides links by permission | API | PASS | `AuthorizationApiTest`, `SecurityControlsApiTest`, `AccessCatalogTest`, `appShell.test.tsx`, `access.test.ts`. Hidden links are not the security boundary; the API tests are. |
| Employee directory and attendance code | PASS | Form rules only | API | PASS | `EmployeeDirectoryApiTest`, `AttendanceIdentifierTest`. Code is hashed. Browser create/edit was not clicked. |
| Attendance cycle and illegal transitions | PASS | Kiosk follows server actions | API and ledger | PASS | `AttendanceStateMachineTest`, `AttendanceLedgerTest`, `TerminalApiTest`. |
| Duplicate and conflicting terminal writes | PASS | Kiosk reuses one idempotency key | API and ledger | PASS | `testARepeatedIdempotencyKeyReturnsTheOriginalEvent`, `testTwoTerminalsCannotAppendConflictingClockIns`. |
| Corrections | PASS | Validation unit test | API | PASS | `AttendanceCorrectionApiTest` keeps the original event. |
| Reports, worked time, missing clock-out, team scope | PASS | Report range unit test | API | PASS | `AttendanceReportApiTest`, `AttendanceDaySummaryTest`, `AttendanceTimeReportTest`. |
| CSV or PDF export | Absent | Absent | Absent | NOT IMPLEMENTED | No export action in `frontend/src`. |
| Device registration, rotation, suspend, revoke | PASS | Not driven in the browser | API | PASS | `DeviceManagementApiTest` rejects network identifiers as credentials. |
| Fingerprint and camera workflow | Mock PASS; production adapter refuses samples | Mock kiosk PASS | No hardware | PARTIAL | `HardwareAdapterTest`, `terminalMachine.test.ts`, `KioskPage.test.tsx`. Production aliases are unavailable adapters. |
| Photograph access and retention | PASS | Not driven in the browser | API | PASS | `AttendancePhotoApiTest`, `ImageInspectorTest`. |
| UTC storage, tenant zones, DST, midnight | PASS | Format tests | API | PASS | `UtcTimeTest`, `TimezoneApiTest`, ledger and day-summary DST cases. |
| Tenant theme and language | PASS | Branding and locale tests | Unsigned pages only | PASS | `TenantConfigurationApiTest`. Live: platform login is Swedish; `acme.localhost` login title is Acme AB, English "Sign in", primary `#9B2C2C`. |
| Domain events and webhooks | PASS on test transport | n/a | No third party | PARTIAL | `DomainEventIsolationTest` covers retry and dead letter. No live webhook URL was called. |
| Signed-in product UI | APIs covered | Shell, kiosk, explorer, platform, usage | Not signed in | PARTIAL | Component tests pass. HR tables, dialogs, and settings were not clicked in the browser. |
| Responsive layout | n/a | Login measured | Unsigned only | PARTIAL | Nine widths on the platform login had no document overflow. Signed-in layouts were not measured. |
| Vendor biometric SDK | Absent | Dev mock only | n/a | NOT IMPLEMENTED | `docs/terminal-hardware.md`. Production boot does not load a vendor SDK. |
| Browser end-to-end and load suites | Absent | Absent | Absent | NOT IMPLEMENTED | Stated in `docs/testing.md`. |
| Live MySQL tenant writes and cross-tenant probes | Not run | Not run | Not run | BLOCKED | Would write or read live tenant databases. SQLite suite was used instead. |
| Physical terminal certification | Not run | Not run | Not run | NOT TESTED | No reader or camera was attached. |
| Production frontend bundle | `tsc --noEmit` passed | `vite build` not run | n/a | NOT TESTED | Build writes `dist`. Typecheck and lint passed. |
| Redis and RabbitMQ as running services | Disabled in PHPUnit | n/a | Not exercised | NOT TESTED | Compose may provide them. This audit did not publish to the broker. |

## D. Defect Register

### AUD-001 — PHPStan level 6 fails

- Feature/module: static analysis, `Hardware` and `Integration`.
- Severity: LOW.
- Status: confirmed. PHPUnit still passes.
- Expected: `composer phpstan` / `phpstan analyse` exits 0, which is the check named in `docs/testing.md`.
- Actual: 6 errors, exit code 1.
- Steps: `docker compose exec -T php ./vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
- Files:
  - `backend/src/Hardware/BiometricLogProcessor.php` line 31. `is_string($key)` is always true for the declared `array<string, mixed>`.
  - `backend/src/Integration/IntegrationConfig.php` line 29. `is_string($key)` is always true for the declared `array<string, mixed>`.
  - `backend/src/Integration/TenantDomainEventMessage.php` line 33. Four errors: the key is always a string, and the scalar-or-null check is always false under the `EventPayload` PHPDoc.
- Root cause: PHPStan treats the PHPDoc array shapes as certain, so the defensive `is_string` / `is_scalar` guards are dead inside the analyzer. The guards still exist at runtime if a value does not match the annotation.
- Business impact: no reproduced wrong attendance record or data leak. The documented quality gate is red, so a later type error can hide in the same output.
- Recommended fix: narrow the loops with a local type that PHPStan accepts, or annotate the integer-key case explicitly, without removing the runtime rejection of non-scalar event payloads. Do not turn off `treatPhpDocTypesAsCertain` globally just to silence these lines.
- Regression test: `phpstan analyse` exits 0. Re-run `IntegrationConfigTest` and `AuditMetadata` / hardware log tests so redaction and payload checks still reject bad values.

### AUD-002 — No version control metadata

- Severity: INFO.
- Status: environment fact.
- Actual: `git status --short --branch` fails because the directory is not a repository.
- Impact: this audit cannot show a diff of existing local edits, including the previously approved explorer database URLs in `backend/.env`.
- Recommended fix: initialize or clone a repository when you want history. Not an application defect.

### AUD-003 — Acme login rendered no logo image

- Severity: INFO.
- Status: observation, not a confirmed defect.
- Actual: on `http://acme.localhost:8080/login`, the document title is `Acme AB`, the heading is `Sign in`, `--color-primary` is `#9B2C2C`, and `document.querySelector('img')` is null.
- Impact: unknown. A text-only brand is valid if that tenant has no logo configured. This audit did not read the tenant branding record.
- Recommended check: compare the rendered header with the stored logo for Acme before treating this as a bug.

No critical, high, or medium defect was reproduced.

## E. Security Findings

### Confirmed by tests in this run

These passed inside the SQLite suite. They were not re-executed against live MySQL.

- Another tenant's rows are not readable (`TenantIsolationTest`).
- A session cookie does not cross hosts (`AuthorizationApiTest::testASessionCannotCrossTenantsAndADisabledTokenStopsWorking`).
- A tenant device signature on the other host is `tenant_mismatch` (`TerminalApiTest`, as mapped in `docs/testing.md`).
- Platform routes stay on the platform host. Tenant sessions are not platform operators.
- `platform.database.explore` is only on `platform_admin` (`AccessCatalog.php` lines 96–101).
- Unsigned `GET /api/v1/platform/databases` returned HTTP 401 during this audit.
- Unsigned `GET /api/v1/auth/session` returned HTTP 200 with `authenticated: false` and empty roles. That is the public anonymous payload, not a signed-in principal.
- Device tests reject MAC, IP, hostname, and process id as credentials (`DeviceManagementApiTest`, `IpRangeTest`).
- Attendance code, photograph bytes, and biometric samples are redacted in the tests named in `docs/testing.md`.
- Archive and drop require the slug as confirmation. Drop was not called on a live tenant.
- SQL console tests reject writes, protected names, cross-database qualifiers, and comment or function bypasses (`DatabaseExplorerApiTest`).
- Quota overlap test: two concurrent employee creates against a limit of 1 finish as one `CREATED` and one `QUOTA`.

### Unverified risks

- Live MySQL grants for `explorer_platform` and `explorer_tenant` were checked in an earlier session, not again in this audit. This run does not re-prove those grants.
- The signed-in explorer HTTP call was not repeated, so this audit does not prove the previous HTTP 503 is gone in the browser.
- SQLite explorer tests do not set `MAX_EXECUTION_TIME`. The MySQL explorer connection is written to set a read-only transaction and a 5 second execution limit. That MySQL session behavior was not timed out on purpose here.
- Column hiding is by column name. A secret stored in an ordinary column is still returned. That is the documented policy, not a new bypass.
- Explorer rate limiting is cache-based. The unit test covers the sequential gate. A multi-worker race on that cache was not load-tested.
- Real webhook delivery, Redis, and RabbitMQ were not exercised. A broker misconfiguration would not show up in this suite.
- Production fingerprint matching is the unavailable adapter. A terminal in production cannot complete a real fingerprint step until a vendor adapter is installed. That is fail-closed, not an authentication bypass.

No confirmed cross-tenant data leak, privilege escalation, or destructive SQL bypass was reproduced in this audit.

## F. Database and Data Integrity

- PHPUnit forces `PLATFORM_DATABASE_URL` and the tenant URL to SQLite files under the backend var directory, and forces explorer URLs to SQLite. Messenger is `in-memory://`. Redis is `disabled://`. The suite is isolated from the live MySQL databases.
- Result: 114 tests, 2645 assertions, OK. A first run that passed `--do-not-cache-result` reported one PHPUnit deprecation for that flag. A rerun without the flag was a clean OK. The deprecation is from the audit command, not from application code.
- Schema migrations were not applied to live tenants. The SQLite tests migrate their own files.
- Attendance events are append-only in `AttendanceLedgerTest` and `AttendanceCorrectionApiTest`. Invalid transitions do not insert a second conflicting clock-in.
- Quota enforcement is server-side. `TenantQuotaService::lockGuard()` updates `quota_guard` (lines 128–139) before capacity checks. The overlap API test is the concurrency evidence. It uses SQLite, not MySQL row locks under load.
- The MySQL provisioner (`MysqlTenantDatabaseProvisioner`) creates a database and a dedicated user. Tests replace it with `SqliteTenantDatabaseProvisioner` (`services.yaml` lines 90–91). Partial MySQL failure behavior is therefore not what PHPUnit runs.
- Employee and user limits are separate counters. Creating a user account reserves both a user and an employee (`TenantQuotaService::reserveUserAccount`, lines 115–119).

## G. Frontend and UX Findings

### What was rendered

Platform login at `http://localhost:8080/login` (Swedish copy, heading "Logga in"):

| Width | Document overflow |
| --- | --- |
| 320 | No (`scrollWidth` 320) |
| 375 | No |
| 390 | No |
| 430 | No |
| 768 | No |
| 1024 | No |
| 1280 | No |
| 1440 | No |
| 1920 | No |

At 320 the header shows the product name and a Menu button. The email and password fields and the submit button fit. At 1920 the sidebar is visible with Grund and Kiosk, and the form sits in the content column. No overlapping header was seen on those two screenshots.

`http://acme.localhost:8080/login` at the browser's current width (606px) had no document overflow, English "Sign in", title `Acme AB`, and primary color `#9B2C2C`. No logo `img` was present (AUD-003).

Unsigned `GET /api/v1/health` returned `status: ok` and a UTC timestamp. `GET /api/v1/health/ready` returned 200.

### What was not rendered

Signed-in dashboard, employee tables, attendance history, corrections, reports, settings, device management, database explorer, quota editor, dialogs, and the kiosk were not opened as a signed-in user. Their component tests cover loading, empty, and error states for the platform list, quotas, usage, explorer, shell navigation, and kiosk result. Those tests do not prove layout at 320–1920.

Accessibility was not audited beyond visible labels on the login form. Keyboard order, contrast, and screen-reader names were not measured.

## H. Test Execution Log

| Command | Purpose | Outcome |
| --- | --- | --- |
| `git status --short --branch` | See branch and uncommitted files | Not a git repository. No diff. |
| `docker compose exec -T php ./vendor/bin/phpunit` | Full backend suite on forced SQLite | OK. 114 tests, 2645 assertions. About 14 seconds. |
| `docker compose exec -T php ./vendor/bin/phpunit --display-phpunit-deprecations --do-not-cache-result` | Show the earlier deprecation | The only deprecation is the `--do-not-cache-result` flag itself. Tests still OK. |
| `docker compose exec -T frontend npm test` | Vitest once | 21 files, 74 tests passed. About 2.4 seconds. |
| `docker compose exec -T frontend npm run lint` | ESLint | Exit 0. No findings printed. |
| `docker compose exec -T frontend npx tsc --noEmit --pretty false` | Typecheck without emitting files | Exit 0. |
| `docker compose exec -T php ./vendor/bin/phpstan analyse --memory-limit=512M --no-progress` | Level 6 | Exit 1. 6 errors. See AUD-001. |
| `docker compose exec -T php composer validate --no-check-publish --strict` | Composer metadata | `./composer.json is valid`. |
| `curl` unsigned `GET /api/v1/platform/databases` | Explorer without a session | HTTP 401. |
| `curl` unsigned `GET /api/v1/auth/session` on platform and Acme hosts | Anonymous session | HTTP 200, `authenticated: false`. |
| `curl` `GET /api/v1/health` and `/api/v1/health/ready` | Liveness and readiness | HTTP 200. Health body has service name and UTC time only. |
| Browser, platform login, nine widths | Overflow | No document overflow. Screenshots at 320 and 1920. |
| Browser, Acme login | Tenant brand on the public page | Title, language, and primary color differ from the platform host. |

Not executed, and why:

| Check | Reason |
| --- | --- |
| Live MySQL PHPUnit or tenant migrate | `phpunit.dist.xml` already isolates the suite. Writing `acme`, `beta`, or `shawqi` is unsafe. |
| `POST` drop-database on a real tenant | Explicitly out of scope. |
| Destructive SQL in the explorer | Explicitly out of scope. Tests already reject writes on SQLite. |
| Signed-in browser flows | Would use a real tenant session. Not started. |
| `npm run build` (`tsc -b && vite build`) | Writes build output. `tsc --noEmit` was used instead. |
| Vendor SDK, camera, fingerprint reader | Not present. Mocks only. |
| Redis, RabbitMQ, external webhooks | Not required by the suite and not called. |
| Dependency installation or upgrades | Forbidden for this audit. Existing vendor and node_modules were enough. |

## I. Prioritized Remediation Plan

No fix in this list was implemented.

### P0 — Critical security or data-integrity blockers

None confirmed.

### P1 — High-impact defects

None confirmed.

Before any production use of a new tenant on MySQL, re-check these without treating them as already failed:

- Create one disposable tenant on MySQL, migrate it, suspend it, and confirm the database still exists. Do not drop `acme`, `beta`, or `shawqi`.
- Sign in as platform admin and open `GET /api/v1/platform/databases`. Expect 200, not 503.
- Run one mock terminal cycle on a disposable tenant and compare the stored UTC instant with the report in that tenant's timezone.

### P2 — Medium-impact follow-up

1. Clear the six PHPStan errors (AUD-001).
   - Files: `BiometricLogProcessor.php`, `IntegrationConfig.php`, `TenantDomainEventMessage.php`.
   - Expected result: `phpstan analyse` exits 0 and the existing unit tests still reject bad payloads and still redact biometric context.
   - Side effect: low, if the runtime guards stay in place.
2. Signed-in visual pass at 320, 768, 1024, and 1440 for employees, HR tables, corrections, reports, settings, and the database explorer.
   - Needs a disposable tenant or an agreed test account. Do not use real employee photos.
3. Re-verify live explorer grants and the authenticated catalog request. Do not print the explorer passwords.

### P3 — Low-priority and missing product scope

- CSV or PDF export, if the product should have it. It is not in the codebase today.
- A vendor fingerprint and camera adapter behind the existing ports. Keep the unavailable adapter as the production default until that adapter exists.
- A browser end-to-end suite and a load test for quota and terminal conflicts. The API overlap test is the current concurrency evidence.
- Put the project in Git so later audits can see the diff.
- Confirm whether Acme is supposed to have a logo image (AUD-003).

## J. Remaining Unknowns

- Whether the running MySQL explorer accounts still match `backend/.env`, and whether a signed-in catalog request returns 200.
- Whether `MysqlTenantDatabaseProvisioner` rolls back a partial MySQL user or database on failure. Tests cover the SQLite provisioner and a forced incomplete status.
- Layout of signed-in tables, dialogs, and the kiosk at the nine requested widths.
- Behavior of the Compose Redis and RabbitMQ services.
- Delivery to a real webhook URL.
- Any physical reader, camera, MAC allow-list, or terminal build.
- Whether the Acme tenant record contains a logo that the login page failed to show.
- Contents of local uncommitted edits, because there is no Git metadata.
- Production asset output of `vite build`.

## K. Overall Readiness Assessment

The application code that the automated suite exercises is consistent with the documented attendance contract:

`OFF → clock in → WORKING → lunch start → ON_LUNCH → lunch end → WORKING → clock out → OFF`

Illegal transitions, idempotency, tenant host binding, role checks, quota overlap, UTC storage, DST duration, photograph authorization, and the read-only SQL console passed on SQLite.

Verified this audit:

- Full PHPUnit suite (114 / 2645).
- Full Vitest suite (74).
- ESLint and TypeScript `--noEmit`.
- Unsigned health, anonymous session, and explorer rejection.
- Public login overflow at nine widths, and a visible brand difference between the platform host and Acme.

Defective:

- PHPStan level 6 (AUD-001, low).

Not verified:

- Live MySQL tenant isolation and provisioning.
- Signed-in product UI and responsive layouts beyond the login page.
- Physical biometrics.
- Real queues and webhooks.
- Production frontend build.

Do not treat this application as production-ready for physical terminals or for untested live MySQL operations solely because the SQLite suite is green.
