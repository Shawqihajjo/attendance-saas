<?php

declare(strict_types=1);

namespace App\Integration;

use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;

/**
 * Tenant configuration is data. It is never treated as PHP, a class name, or a shell command.
 */
final class IntegrationConfig
{
    /**
     * @param array<mixed> $config
     *
     * @return array<string, string>
     */
    public function validate(string $kind, string $eventName, array $config): array
    {
        if ($eventName !== '*') {
            DomainEventName::require($eventName);
        }
        if (!in_array($kind, ['webhook', 'notification', 'email', 'internal', 'queue'], true)) {
            throw new InvalidTenantInputException('The integration kind is not supported.');
        }
        $values = [];
        foreach ($config as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new InvalidTenantInputException('Integration configuration must be text values.');
            }
            $normalized = strtolower($key);
            if (in_array($normalized, ['code', 'php', 'class', 'file', 'script', 'eval', 'callback', 'command', 'shell'], true)) {
                throw new InvalidTenantInputException('Integration configuration cannot contain executable code.');
            }
            if (str_contains($value, '<?php') || str_contains($value, '<?=')) {
                throw new InvalidTenantInputException('Integration configuration cannot contain executable code.');
            }
            $values[$key] = $value;
        }

        return match ($kind) {
            'webhook' => $this->webhook($values),
            'notification' => $this->expect($values, 'audience', ['tenant']),
            'email' => $this->email($values),
            'internal' => $this->expect($values, 'handler', ['audit']),
            'queue' => $this->expect($values, 'name', ['attendance']),
        };
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private function webhook(array $values): array
    {
        $url = $values['url'] ?? '';
        $parts = parse_url($url);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass']) || $this->blockedHost($host)) {
            throw new InvalidTenantInputException('A webhook URL must be an https address.');
        }
        if (count($values) !== 1) {
            throw new InvalidTenantInputException('A webhook accepts only a url.');
        }

        return ['url' => $url];
    }

    /**
     * @param array<string, string> $values
     * @param list<string> $allowed
     *
     * @return array<string, string>
     */
    private function expect(array $values, string $key, array $allowed): array
    {
        $value = $values[$key] ?? '';
        if (count($values) !== 1 || !in_array($value, $allowed, true)) {
            throw new InvalidTenantInputException('The integration configuration is not supported.');
        }

        return [$key => $value];
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private function email(array $values): array
    {
        $to = strtolower(trim($values['to'] ?? ''));
        if (count($values) !== 1 || $to === '' || strlen($to) > 180 || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidTenantInputException('The email recipient is invalid.');
        }

        return ['to' => $to];
    }

    private function blockedHost(string $host): bool
    {
        if (in_array($host, ['localhost', 'metadata.google.internal'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
