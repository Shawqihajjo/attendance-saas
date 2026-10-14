# Terminal hardware

A physical terminal is a computer running the kiosk at `/kiosk`. The Symfony API never talks to a USB device. The kiosk talks to local hardware through `TerminalDeviceService`, then sends the result to the API. The attendance state machine and the ledger do not import a vendor SDK. The browser does not discover fingerprint readers by itself.

## Ports

The kiosk depends on two ports in `frontend/src/features/kiosk/hardware.ts`.

`FingerprintDevice`:

- `vendorId()` names the adapter.
- `availability()` returns `available`, `unavailable`, or `disconnected`.
- `health()` returns healthy or unhealthy, plus an error code when unhealthy.
- `enrollmentStatus(employeeRef)` returns `enrolled`, `not_enrolled`, or `unknown`. The mock returns `unknown` because it stores no template. A later vendor adapter can answer from a template reference.
- `capture(attemptId)` returns an opaque sample or a failure.

`CameraDevice`:

- `vendorId()`, `availability()`, and `health()` match the fingerprint port.
- `capture(attemptId)` returns image bytes or a failure.

`TerminalDeviceService` is what the kiosk calls. It checks availability before capture. A disconnected or unavailable device returns an error code and does not produce a sample or a photograph.

The server port is `FingerprintReader` in `App\Hardware\Fingerprint`. `HardwareFingerprintMatcher` is the only attendance implementation of `FingerprintMatcher`, and it delegates to that reader. `CameraDevice` in `App\Hardware\Camera` is the same camera contract for tests and for an in-process adapter. The HTTP API still receives the photograph from the terminal. It does not open a camera.

## Errors

| Code | Meaning |
| --- | --- |
| `device_unavailable` | No reader or camera is configured, or the matcher is not installed |
| `device_disconnected` | The device was present and the link is gone |
| `fingerprint_mismatch` | The reader ran and the fingerprint did not match |
| `timeout` | The device did not answer in time |
| `capture_failed` | The capture started and did not produce a usable result |
| `hardware_communication_failure` | The terminal could not complete the exchange with the device |

These codes are the failure result. They do not include a sample, a template, or image bytes.

## Mocks

`MockFingerprintAdapter` and `MockCameraAdapter` run in development, in automated tests, and nowhere else for a real attendance decision. The fingerprint mock accepts vendor `mock` and sample `dev-verify`. It does not compare a template and it does not store the sample. Constructing the PHP mock in `prod` fails. A production build without a bridge uses the unavailable adapters and does not invent a scan or a photograph.

A test can pass a scripted fault, such as `timeout` or `device_disconnected`, and assert the error code.

## What is not stored or logged

The employee id and the biometric payload stay in different values. `BiometricClaim` and `CapturedPhotograph` expose a vendor or content type and a byte length to JSON and to debug output. The payload itself is not part of that view. Audit metadata drops `sample`, `fingerprint`, `template`, `biometric`, `image`, `bytes`, and `photograph`. A Monolog processor redacts those keys in application logs. The mock fingerprint adapter does not write a fingerprint image. A future vendor adapter may keep a template reference in the tenant database. It stores a raw fingerprint image only when that vendor's integration explicitly requires the image, and that storage stays inside the vendor adapter.

## Adding a vendor adapter

1. Implement `FingerprintDevice` and, when the terminal has a camera SDK, `CameraDevice` in a new file under the vendor's name. Do not edit the attendance ledger or the kiosk state machine.
2. On the server, implement `FingerprintReader` in `App\Hardware\Fingerprint` or a vendor namespace that does not get imported by the attendance domain. Map vendor failures onto `HardwareError`. Return a verification id, not the sample.
3. Keep the vendor SDK inside that adapter. The kiosk and `HardwareFingerprintMatcher` already depend on the ports.
4. Assign the terminal adapters to `window.attendanceHardware` before the kiosk loads:

```js
window.attendanceHardware = {
  fingerprint: new VendorFingerprintAdapter(),
  camera: new VendorCameraAdapter(),
}
```

The bridge is a local program or a wrapper around the vendor SDK. It is not a web page using WebUSB.

5. Point the `FingerprintReader` service alias at the new server adapter in the production service configuration. Leave `MockFingerprintAdapter` on `dev` and `test`.
6. Add a test that uses the port with a fake vendor response. Do not log the sample in that test's assertion output. Confirm a mismatch, a timeout, and a disconnected reader return the error codes above, and that enrollment status returns a status rather than a template.

Two server integrations fit the same reader port:

- Server-side match. The bridge submits a sample or template. The reader compares it with the employee's template reference in the tenant database. This is the preferred mode.
- Terminal assertion. The vendor SDK cannot leave the terminal. The enrolled device signs a result that names the attempt, the employee, and the outcome. The server checks that signature, its freshness, and the employee binding. This is not the same as a server-side template match.

The attendance state machine does not branch on which mode is active.

## Camera bytes

The API accepts JPEG or PNG up to 2 MB. It checks the declared type, the image signature and ending marker, and rejects active-content markers. The bytes are stored outside the database. The tenant row and the attendance event keep the photo id. The upload response is that id. The commit reads the id from the attempt. The kiosk cannot substitute a photo id from another attempt. Those bytes are the attendance photograph, taken after a successful fingerprint check. They are not a fingerprint image and they are not published by the terminal. Viewing and deletion are described in `docs/media-storage.md`.

## What the kiosk decides

The kiosk collects the attendance code, shows the employee the server identified, captures hardware results, and continues only with actions the server returns after fingerprint verification. It does not decide whether clock-in, lunch, or clock-out is legal. A photograph is taken when the server marks that action as requiring one. A failed fingerprint or camera capture does not create an attendance event. The success screen shows the name, the recorded action, and the local terminal time, then returns to idle.

The official attendance time is the server clock. The terminal clock is not sent as `occurredAt`.

## Device identity

Fingerprint hardware is not a device credential. Each terminal has an Ed25519 key. The private key stays on the terminal. `app:device:publish-key` stores the public key and creates the tenant device projection, including its IANA timezone. The device must be `active` before the API accepts a signature. Requests use `X-Device-Id`, `X-Device-Timestamp`, `X-Device-Nonce`, and `X-Device-Signature`. A web session cookie cannot call `/api/v1/terminal`.
