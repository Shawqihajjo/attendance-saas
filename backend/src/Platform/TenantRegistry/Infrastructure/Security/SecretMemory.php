<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Security;

final class SecretMemory
{
    public static function forget(string &$secret): void
    {
        $copy = $secret;
        $secret = '';
        try {
            if ($copy !== '') {
                sodium_memzero($copy);
            }
        } catch (\Throwable) {
        }
    }
}
