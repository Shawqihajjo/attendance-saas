<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Domain;

use App\Shared\Http\ApiProblemExtension;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class TenantQuotaExceededException extends ConflictHttpException implements ApiProblemExtension
{
    public function __construct(
        private readonly string $resource,
        private readonly int $current,
        private readonly int $limit,
    ) {
        parent::__construct(self::messageFor($resource));
    }

    public function problemCode(): string
    {
        return 'tenant_quota_exceeded';
    }

    public function problemTitle(): string
    {
        return 'Quota exceeded';
    }

    public function resource(): string
    {
        return $this->resource;
    }

    public function current(): int
    {
        return $this->current;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * @return array{resource: string, current: int, limit: int}
     */
    public function problemExtensions(): array
    {
        return [
            'resource' => $this->resource,
            'current' => $this->current,
            'limit' => $this->limit,
        ];
    }

    private static function messageFor(string $resource): string
    {
        return match ($resource) {
            'users' => 'The user limit has been reached.',
            'employees' => 'The employee limit has been reached.',
            'devices' => 'The device limit has been reached.',
            default => 'The tenant limit has been reached.',
        };
    }
}
