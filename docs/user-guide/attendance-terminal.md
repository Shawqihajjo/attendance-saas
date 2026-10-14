# User guide — Attendance terminal (kiosk)

**Path:** `/kiosk` on the **tenant** host  
**Auth:** device enrollment + signed API calls (not a normal user session)

## Implemented flow

1. **Employee code** scanned or typed  
2. Server identifies the employee in **this tenant**  
3. **Fingerprint** verification via hardware port (mock in development)  
4. **Photograph** when the server requires it (typically clock-in)  
5. Server validates the legal action and **commits** with the server UTC clock  
6. Success shows name, action, and local terminal time, then returns to idle  

Failures (scanner, fingerprint, camera, network) can be retried; the same attempt keeps one idempotency key so duplicate commits do not create duplicate events.

## Enrollment

1. Tenant admin opens `/terminals` and registers a device.  
2. Kiosk enters the one-time code.  
3. Browser stores `{ deviceId, seed }` in `localStorage` key `terminal.credential`.  

Without enrollment, attendance commits are refused.

## When something is unavailable

| Problem | Behavior / action |
| --- | --- |
| Readiness failed | Idle screen waits; do not accept codes |
| Fingerprint mismatch | Retry; development mock accepts only the documented sample |
| Camera failure | Retry photo step when required |
| Network error | UI shows network reason; nothing recorded server-side until commit succeeds |
| Device suspended/revoked | Terminal API rejects authenticated device |

## Mock vs production hardware

| Mode | Fingerprint / camera |
| --- | --- |
| Development without bridge | Mock adapters |
| Production | Unavailable until a vendor adapter is integrated |
| Optional | `window.attendanceHardware` bridge |

See [../architecture/devices-and-hardware.md](../architecture/devices-and-hardware.md) and [../terminal-hardware.md](../terminal-hardware.md).
