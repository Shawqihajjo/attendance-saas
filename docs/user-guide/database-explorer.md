# User guide — Database Explorer and SQL Console

**Status:** Implemented for platform operators  
**Permission:** `platform.database.explore`  
**UI:** `/platform/databases`  
**Prerequisite:** `EXPLORER_PLATFORM_DATABASE_URL` and `EXPLORER_TENANT_DATABASE_URL` set to **separate read-only** MySQL accounts. If empty, the API returns **503**.

This repository does **not** ship an automated script that creates those read-only MySQL users. An operator must create SELECT-only accounts (platform schema and `tenant_%` databases as appropriate) and place their URLs in the environment. Until then, leave the variables empty and expect 503 from explorer endpoints.

**Never** put the application read/write MySQL password into explorer URLs.

## Choose a database

`GET /api/v1/platform/databases` lists targets: `platform` or a tenant **slug**.

## Inspect tables and rows

| Action | Endpoint |
| --- | --- |
| List tables | `GET /api/v1/platform/databases/{target}/tables` |
| Table metadata | `GET /api/v1/platform/databases/{target}/tables/{table}` |
| Browse rows | `GET /api/v1/platform/databases/{target}/tables/{table}/rows` |

Row browsing defaults to limit **50** (max **100**) with offset pagination.

## SQL Console (read-only)

`POST /api/v1/platform/databases/{target}/query` with JSON fields `sql`, `limit`, `offset`.

**Allowed:** `SELECT`, read-only `WITH`, `SHOW TABLES`, `SHOW COLUMNS`, `DESCRIBE`, `EXPLAIN` of those.  
**Rejected:** writes, other databases, protected objects.  
**Caps:** result max **1000** rows; statement body size limited (docs: 8000 bytes).

Audit stores a **statement hash**, not the SQL text or result rows.

## Security warnings

- Explorer is for operators with the explore permission only.  
- Do not attempt to bypass read-only restrictions.  
- Do not run explorer queries against production without change-control and a read-only account.  
- Do not paste query results containing personal data into public channels.
