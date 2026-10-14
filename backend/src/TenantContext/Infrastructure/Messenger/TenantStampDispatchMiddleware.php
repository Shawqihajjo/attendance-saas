<?php

declare(strict_types=1);

namespace App\TenantContext\Infrastructure\Messenger;

use App\Integration\TenantDomainEventMessage;
use App\TenantContext\Domain\TenantContextHolder;
use App\TenantContext\Domain\TenantStampMismatchException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class TenantStampDispatchMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly TenantContextHolder $context)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $current = $this->context->current();
        $stamp = $envelope->last(TenantStamp::class);
        $message = $envelope->getMessage();
        if ($message instanceof TenantDomainEventMessage) {
            if ($current !== null && $message->tenantId !== $current->tenantId) {
                throw new TenantStampMismatchException();
            }
            if ($stamp instanceof TenantStamp && $stamp->tenantId !== $message->tenantId) {
                throw new TenantStampMismatchException();
            }
            if (!$stamp instanceof TenantStamp && $current === null) {
                throw new TenantStampMismatchException();
            }
        }
        if ($current !== null) {
            if ($stamp instanceof TenantStamp && $stamp->tenantId !== $current->tenantId) {
                throw new TenantStampMismatchException();
            }
            if (!$stamp instanceof TenantStamp) {
                $envelope = $envelope->with(new TenantStamp($current->tenantId));
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
