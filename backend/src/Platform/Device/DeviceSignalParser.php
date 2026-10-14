<?php

declare(strict_types=1);

namespace App\Platform\Device;

/**
 * Reported device details are metadata. Process id is discarded and is never stored.
 */
final class DeviceSignalParser
{
    /**
     * @param array<string, mixed> $reported
     *
     * @return array{hostname: ?string, osInfo: ?string, appVersion: ?string, macAddress: ?string, networkName: ?string}
     */
    public function parse(array $reported): array
    {
        unset($reported['processId'], $reported['pid'], $reported['ip'], $reported['ipAddress']);

        return [
            'hostname' => $this->text($reported['hostname'] ?? null, 255),
            'osInfo' => $this->text($reported['os'] ?? $reported['osInfo'] ?? null, 120),
            'appVersion' => $this->text($reported['appVersion'] ?? null, 64),
            'macAddress' => $this->mac($reported['macAddress'] ?? null),
            'networkName' => $this->text($reported['network'] ?? $reported['networkName'] ?? null, 120),
        ];
    }

    private function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max || preg_match('/[\\x00-\\x1F\\x7F]/', $value)) {
            return null;
        }

        return $value;
    }

    private function mac(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', trim($value)) !== 1) {
            return null;
        }

        return strtoupper(str_replace('-', ':', trim($value)));
    }
}
