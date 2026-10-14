<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use App\TenantContext\Domain\TenantContextHolder;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;

#[AutoconfigureTag('monolog.processor')]
final class RequestContextProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TenantContextHolder $tenants,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;
        $request = $this->requestStack->getCurrentRequest();
        $requestId = $request?->attributes->get('request_id');
        if (is_string($requestId) && $requestId !== '') {
            $extra['request_id'] = $requestId;
        }

        $tenant = $this->tenants->current();
        if ($tenant !== null) {
            $extra['tenant_id'] = $tenant->tenantId;
        }

        return $record->with(extra: $extra);
    }
}
