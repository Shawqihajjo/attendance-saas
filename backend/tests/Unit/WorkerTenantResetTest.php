<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\TenantContext\Domain\TenantDatabaseSwitcher;
use App\TenantContext\Infrastructure\Messenger\WorkerTenantReset;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

final class WorkerTenantResetTest extends TestCase
{
    public function testTheWorkerClearsTenantStateWhenItStartsAndAfterEveryMessage(): void
    {
        $switcher = new class implements TenantDatabaseSwitcher {
            public int $resets = 0;

            public function switchTo(string $tenantId): void
            {
            }

            public function switchForMaintenance(string $tenantId): void
            {
            }

            public function reset(): void
            {
                ++$this->resets;
            }
        };
        $reset = new WorkerTenantReset($switcher);
        $worker = new Worker([], $this->createStub(MessageBusInterface::class));
        $envelope = new Envelope(new \stdClass());

        $reset->onWorkerStarted(new WorkerStartedEvent($worker));
        $reset->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        $reset->onMessageFailed(new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('failed')));

        self::assertSame(3, $switcher->resets);
    }
}
