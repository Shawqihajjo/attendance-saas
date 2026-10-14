<?php

declare(strict_types=1);

namespace App\TenantContext\Infrastructure\Http;

use App\Shared\Http\ApiProblem;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TenantHostUnknownException extends NotFoundHttpException implements ApiProblem
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct('The host is not a tenant.', $previous);
    }

    public function problemCode(): string
    {
        return 'tenant_not_found';
    }

    public function problemTitle(): string
    {
        return 'Tenant not found';
    }
}
