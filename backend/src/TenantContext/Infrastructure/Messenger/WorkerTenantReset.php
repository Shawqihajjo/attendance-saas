<?php

declare(strict_types=1);

namespace App\TenantContext\Infrastructure\Messenger;

use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * Closes any tenant connection around the worker lifecycle.
 * The consume middleware still switches from the message stamp for the duration of that message.
 */
final class WorkerTenantReset
{
    public function __construct(private readonly TenantDatabaseSwitcher $switcher)
    {
    }

    #[AsEventListener(event: WorkerStartedEvent::class)]
    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $this->switcher->reset();
    }

    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->switcher->reset();
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->switcher->reset();
    }
}
