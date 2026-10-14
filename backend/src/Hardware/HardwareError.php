<?php

declare(strict_types=1);

namespace App\Hardware;

enum HardwareError: string
{
    case DeviceUnavailable = 'device_unavailable';
    case DeviceDisconnected = 'device_disconnected';
    case FingerprintMismatch = 'fingerprint_mismatch';
    case Timeout = 'timeout';
    case CaptureFailed = 'capture_failed';
    case HardwareCommunicationFailure = 'hardware_communication_failure';
}
