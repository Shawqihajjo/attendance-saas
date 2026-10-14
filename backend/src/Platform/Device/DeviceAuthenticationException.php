<?php

declare(strict_types=1);

namespace App\Platform\Device;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class DeviceAuthenticationException extends AuthenticationException
{
    public function __construct(
        private readonly string $problemCode,
        string $message,
        private readonly int $status = 401,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function problemCode(): string
    {
        return $this->problemCode;
    }

    public function status(): int
    {
        return $this->status;
    }
}
