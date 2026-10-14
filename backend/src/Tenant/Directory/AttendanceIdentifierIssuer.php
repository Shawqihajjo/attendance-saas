<?php

declare(strict_types=1);

namespace App\Tenant\Directory;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The attendance identifier identifies which employee record a terminal attempt refers to.
 * It is not biometric verification. Fingerprint verification remains a separate step.
 * Only a keyed hash is stored. The typed identifier is returned once, when it is issued.
 */
final class AttendanceIdentifierIssuer
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /**
     * @return array{code: string, hash: string}
     */
    public function issue(): array
    {
        $raw = '';
        $length = strlen(self::ALPHABET);
        for ($index = 0; $index < 10; ++$index) {
            $raw .= self::ALPHABET[random_int(0, $length - 1)];
        }
        $code = substr($raw, 0, 4).'-'.substr($raw, 4, 4).'-'.substr($raw, 8, 2);

        return ['code' => $code, 'hash' => $this->hash($code)];
    }

    public function hash(string $code): string
    {
        $normalized = strtoupper(str_replace(['-', ' '], '', trim($code)));

        return hash_hmac('sha256', $normalized, $this->secret);
    }
}
