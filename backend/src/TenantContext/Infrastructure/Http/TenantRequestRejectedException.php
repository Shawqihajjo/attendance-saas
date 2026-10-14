<?php

declare(strict_types=1);

namespace App\TenantContext\Infrastructure\Http;

use App\Shared\Http\ApiProblem;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class TenantRequestRejectedException extends AccessDeniedHttpException implements ApiProblem
{
    public function __construct(
        private readonly string $problemCode,
        string $message,
        \Throwable $previous,
    ) {
        parent::__construct($message, $previous);
    }

    public function problemCode(): string
    {
        return $this->problemCode;
    }

    public function problemTitle(): string
    {
        return 'Forbidden';
    }
}
