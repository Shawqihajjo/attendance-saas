<?php

declare(strict_types=1);

namespace App\TenantContext\Infrastructure\Messenger;

use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Switches the tenant connection only for messages a worker received from a transport.
 * Dispatch from an HTTP request must leave the current connection alone.
 */
final class TenantSwitchMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly TenantDatabaseSwitcher $switcher)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(ReceivedStamp::class) === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        $this->switcher->reset();
        try {
            $stamp = $envelope->last(TenantStamp::class);
            if ($stamp instanceof TenantStamp) {
                $this->switcher->switchTo($stamp->tenantId);
            }

            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->switcher->reset();
        }
    }
}
