<?php

declare(strict_types=1);

namespace App\Shared\Logging;

final class SecretRedactor
{
    public static function redact(string $message): string
    {
        $redacted = preg_replace('#://[^:\s/]*:[^@\s/]+@#', '://***:***@', $message);

        return is_string($redacted) ? $redacted : $message;
    }
}
