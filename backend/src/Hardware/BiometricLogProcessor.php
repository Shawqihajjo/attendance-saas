<?php

declare(strict_types=1);

namespace App\Hardware;

use App\Hardware\Camera\CapturedPhotograph;
use App\Hardware\Fingerprint\BiometricClaim;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

#[AsMonologProcessor]
final class BiometricLogProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: self::redact($record->context),
            extra: self::redact($record->extra),
        );
    }

    /**
     * Log context may contain list keys. Only string keys are matched against secret names.
     *
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && self::sensitive($key)) {
                $values[$key] = '[redacted]';
                continue;
            }
            if ($value instanceof BiometricClaim || $value instanceof CapturedPhotograph) {
                $values[$key] = $value->redacted();
                continue;
            }
            if (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }

    private static function sensitive(string $key): bool
    {
        return in_array(strtolower($key), [
            'sample',
            'fingerprint',
            'template',
            'biometric',
            'biometricpayload',
            'image',
            'imagebytes',
            'bytes',
            'photograph',
        ], true);
    }
}
