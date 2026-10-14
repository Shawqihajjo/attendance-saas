# Attendance photograph storage

An attendance event stores a photo id. The image bytes live in a `MediaStore`. The database row stores the type, size, SHA-256, storage driver, optional retention instant, and deletion instant. It does not store the image.

The default sequence is unchanged: attendance code, employee identified, fingerprint verified, camera capture, attendance event. The capture step returns `{ "photoId": "..." }`. A client filename is ignored. The download name is always `attendance-photo.jpg` or `attendance-photo.png`.

## Replaceable store

`App\Media\MediaStore` is the port:

| Method | Responsibility |
| --- | --- |
| `name()` | Driver name recorded on the photo row. The local driver is `local` |
| `write(tenantId, mediaId, bytes)` | Stores the bytes and returns an opaque key |
| `read(tenantId, key)` | Returns the bytes for that tenant and key |
| `erase(tenantId, key)` | Overwrites the bytes, then removes the object |

`config/services.yaml` aliases `MediaStore` to `LocalMediaStore`. A later S3-compatible adapter implements the same interface and replaces that alias. Attendance records do not change. Suggested environment for that adapter, supplied by the deployment and never written into source:

| Variable | Purpose |
| --- | --- |
| `MEDIA_STORE` | `local` today. `s3` when that adapter exists |
| `MEDIA_BUCKET` | Bucket name |
| `MEDIA_ENDPOINT` | S3-compatible endpoint, when the deployment uses one |
| `MEDIA_ACCESS_KEY_ID` | Access key from the secret store |
| `MEDIA_SECRET_ACCESS_KEY` | Secret from the secret store |
| `MEDIA_REGION` | Region |

The application does not ship an S3 client. Adding one means a new class behind `MediaStore`, then changing the alias. Existing rows keep `storage_driver`. A read is refused when the row's driver does not match the active store.

## Local files

The local root defaults to `backend/var/photos`, mode `0700`. `MEDIA_LOCAL_DIRECTORY` overrides that directory. Staging and production mount a private volume there and share it between PHP and the Messenger worker. Each file is `{tenant uuid}/{media uuid}`, mode `0600`. The key is only those two ids separated by one slash. `..`, extra slashes, a backslash, a non-UUID, or another tenant's prefix is rejected before a path is built. The resolved directory must stay under the root. Symlinks are not followed.

Nginx and the public frontend do not map this directory. There is no public URL for a terminal photograph.

## What is accepted

`ImageInspector` allows `image/jpeg` and `image/png` up to 2 MB. A JPEG must start with `FF D8 FF` and end with `FF D9`. A PNG must start with the PNG signature and carry `IHDR`. The declared content type must match the signature. A null byte in the type is rejected. The byte sequence `<?php`, `<script`, `<html`, or `javascript:` is rejected. This is a content check for attendance photographs. It is not a general malware scanner.

On read, a stored hash and size are compared with the object, and the inspector runs again. A mismatch is treated as a missing photograph. A photograph stored before the hash column existed has an empty hash. That read still checks the type, size, and active-content markers, and it accepts the earlier JPEG or PNG signature.

## Who can see it

| Request | Result |
| --- | --- |
| `GET /api/v1/hr/attendance-photos/{id}` without a session | 401 |
| The same URL with only a device signature | 401. The terminal firewall does not cover this path |
| Another employee in the same tenant | 403 |
| The employee in the photograph, their manager, or HR | 200 image response |
| A user in another tenant, including a tenant administrator | 404. The row is not in that database |
| `DELETE` by an employee | 403 |
| `DELETE` by a user with `employees.manage` | Overwrites the object, removes it, sets `deleted_at`, clears the key |

The image response uses the stored type, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, and a fixed filename. The body and headers do not contain the storage key or `var/photos`.

Viewing writes `attendance.photo_viewed` with the photo id and employee id. Deletion writes `attendance.photo_deleted`. Expiration writes `attendance.photo_expired`. The audit metadata does not include the bytes, the key, or a path. If the audit write fails, the image is not returned.

## Retention

`PATCH /api/v1/hr/settings/photo-retention` requires `tenant.settings.manage`. The body is `{ "retentionDays": 30 }` or `{ "retentionDays": null }`. Allowed values are 1 through 3650, or null. Null keeps photographs until an authorized delete. A positive value sets `retained_until` to the capture instant plus that many days. An authorized read after that instant overwrites and removes the object, then returns 404.

The current value is also included as `attendance.photoRetentionDays` on the tenant configuration payload.
