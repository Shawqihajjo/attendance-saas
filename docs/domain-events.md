# Domain events

A domain event is published after the tenant transaction commits. A failed integration does not undo attendance, a correction, or a terminal registration. An idempotent attendance replay does not publish again.

## Contract

Every event is a `TenantDomainEventMessage` on the shared Messenger `async` transport.

| Field | Meaning |
| --- | --- |
| `eventId` | UUID created by the server for this publication |
| `tenantId` | Tenant that owned the transaction. This must equal the server `TenantStamp` |
| `name` | One of the names below |
| `occurredAt` | UTC instant, ISO-8601 with a `Z` suffix |
| `subjectId` | UUID of the employee or terminal the event is about |
| `payload` | Scalar values only. No photo bytes, attendance codes, enrollment tokens, reasons, comments, or supporting notes |

The trusted tenant is the `TenantStamp` applied from the active server context. A client cannot set the stamp. Dispatch rejects a message whose tenant id differs from that context. A worker switches to the stamp, then rejects the message if the body names a different tenant. The worker does not open the tenant named by a mismatched body.

## Names

| Name | When it is published | `subjectId` | Payload |
| --- | --- | --- | --- |
| `EmployeeClockedIn` | After a clock-in commit | Employee | `eventId`, `employeeId`, `action`, `occurredAt` |
| `LunchStarted` | After a lunch-start commit | Employee | `eventId`, `employeeId`, `action`, `occurredAt` |
| `LunchEnded` | After a lunch-end commit | Employee | `eventId`, `employeeId`, `action`, `occurredAt` |
| `EmployeeClockedOut` | After a clock-out commit | Employee | `eventId`, `employeeId`, `action`, `occurredAt` |
| `AttendanceCorrectionRequested` | After the correction request is stored | Employee | `correctionId`, `employeeId`, `action`, `occurredAt` |
| `AttendanceCorrectionApproved` | After approval is stored | Employee | `correctionId`, `decision`, `occurredAt` |
| `AttendanceCorrectionRejected` | After rejection is stored | Employee | `correctionId`, `decision`, `occurredAt` |
| `TerminalRegistered` | After a terminal is stored as pending | Terminal | `deviceId`, `status` |
| `TerminalSuspended` | After a terminal is suspended | Terminal | `deviceId`, `status` |

`eventName` may also be `*` when an administrator enables an action for every name.

## Actions

A tenant administrator with `tenant.configuration.manage` registers an action at `POST /api/v1/hr/integrations`. The tenant id comes from the signed-in account. The configuration is encrypted in the platform database. `GET /api/v1/hr/integrations` returns the id, kind, event name, and status.

| Kind | Configuration | Effect |
| --- | --- | --- |
| `webhook` | `url`, `https` only, no userinfo, no local or private host | POST JSON `{eventId, tenantId, name, occurredAt, subjectId, payload}` |
| `notification` | `audience` = `tenant` | Inserts `integration_notifications` in the stamped tenant database |
| `email` | `to`, one email address | Inserts `integration_outbox` in the stamped tenant database. No SMTP session is opened |
| `internal` | `handler` = `audit` | Writes tenant audit `integration.internal` |
| `queue` | `name` = `attendance` | Confirms the shared attendance queue. A tenant cannot name another queue |

Keys named `code`, `php`, `class`, `file`, `script`, `eval`, `callback`, `command`, or `shell` are rejected. A value containing `<?php` or `<?=` is rejected. An unknown kind is rejected and is not loaded as a class.

## Delivery

The worker records `integration_deliveries` in the stamped tenant database.

| Status | Meaning |
| --- | --- |
| `delivered` | The catalog action returned |
| `failed` | The action threw. The row is audited as `integration.delivery_failed` and Messenger retries |
| `dead` | Retries are exhausted, or the stamp and the message tenant disagree. The row is audited as `integration.delivery_dead` and the message is on the `failed` transport |

The `async` transport retries twice. A broker outage while publishing is recorded as `failed` on the tenant that committed the change and does not fail that commit. A stamp mismatch is not treated as a transport outage.

Webhook JSON and outbox rows contain the contract fields above. They do not contain configuration ciphertext, webhook query secrets, or correction free text.
