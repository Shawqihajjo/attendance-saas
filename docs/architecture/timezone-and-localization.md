# Timezone and localization

## Canonical time (implemented)

| Rule | Behavior |
| --- | --- |
| Storage | UTC instants |
| API representation | ISO-8601 with `Z` (for example `2026-10-07T06:42:15Z`) |
| MySQL session | `+00:00` (Compose MySQL command and application SET) |
| PHP default timezone | UTC |

`UtcTime` converts values before persistence. Fixed numeric offsets are rejected for tenant/report zones; use **IANA** names (for example `Europe/Stockholm`).

## Display timezones

| Source | Use |
| --- | --- |
| Tenant settings | Default display zone for the organization |
| Terminal device | Optional display zone for kiosk success screens |
| Browser | Used when reports request `display=browser` |
| Report parameter | `GET /api/v1/hr/reports` accepts `display`, `timezone`, `browserTimezone`, `deviceId`, `date` |

Worked duration on dashboards uses `workedSeconds`, not string-parsed clock labels.

## Daylight saving

Because storage is UTC and display zones are IANA, DST transitions are handled by the timezone database when converting for display. Attendance **events are not rewritten** when DST rules change.

## Localization (i18n)

- Frontend catalogs: English (`en`) and Swedish (`sv`) under `frontend/src/shared/i18n/locales/`
- Tenant enables a subset and chooses a default
- User/terminal language preference is constrained to enabled languages
- API error `code` values are not translated server-side

Branding (logo, colors) and regional formats (date/time format, first day of week) are tenant settings exposed via `GET /api/v1/public/branding` and settings APIs.

## Verification note

UTC storage, tenant IANA settings, and report display parameters are implemented and covered by automated tests referenced in [../testing.md](../testing.md). Physical terminal firmware clocks are **not** trusted for commit time — the server clock is.
