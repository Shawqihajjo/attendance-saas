# Devices and hardware

## Terminal devices

Platform registry tracks devices per tenant. Enrollment issues a one-time code; the kiosk generates an Ed25519 keypair, publishes the public key, and stores the seed in browser `localStorage` under `terminal.credential`.

CLI (development/operators):

```sh
docker compose exec php php bin/console app:device:register acme "Front desk"
docker compose exec php php bin/console app:device:publish-key <device-id> <base64-public-key> --timezone=Europe/Stockholm
docker compose exec php php bin/console app:device:activate <device-id>
```

UI: tenant administrators use `/terminals` / `/settings/terminals` (`terminals.manage`).

Signed terminal requests require headers: `X-Device-Id`, `X-Device-Timestamp`, `X-Device-Nonce`, `X-Device-Signature`. Heartbeats: `POST /api/v1/terminal/heartbeats`.

## Fingerprint and camera adapters

| Environment | Fingerprint | Camera |
| --- | --- | --- |
| Development / test (no bridge) | `MockFingerprintAdapter` | `MockCameraAdapter` |
| Production | `UnavailableFingerprintReader` | `UnavailableCamera` |
| Optional bridge | `window.attendanceHardware = { fingerprint, camera }` | Same |

**Status:** A production fingerprint **vendor SDK is not connected** in this repository. Production correctly refuses the mock adapter. Integrating a vendor is a separate engineering task ([../terminal-hardware.md](../terminal-hardware.md)).

## Photographs

Bytes are stored privately (local volume `MEDIA_LOCAL_DIRECTORY` / `var/photos` in development). Nginx does not expose that directory. HR and the photographed employee can access images through authorized API routes. Object storage (S3) is described as a future adapter in [../media-storage.md](../media-storage.md), not as the current default implementation.
