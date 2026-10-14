<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Integration\DomainEventPublisher;
use App\Integration\RecordingWebhookClient;
use App\Integration\TenantDomainEventMessage;
use App\Integration\TenantIntegrationRegistry;
use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use App\TenantContext\Infrastructure\Doctrine\TenantAwareConnection;
use App\TenantContext\Infrastructure\Messenger\TenantStamp;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Uid\Uuid;

final class DomainEventIsolationTest extends KernelTestCase
{
    public function testDeliveriesStayInTheTenantThatPublishedThem(): void
    {
        self::bootKernel();
        $suffix = bin2hex(random_bytes(3));
        $alpha = $this->provision('evta'.$suffix, 'evta'.$suffix.'.localhost');
        $beta = $this->provision('evtb'.$suffix, 'evtb'.$suffix.'.localhost');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $registry = $this->registry();
        $registry->register($alpha->id(), 'internal', 'EmployeeClockedIn', ['handler' => 'audit'], $now);
        $registry->register($alpha->id(), 'notification', 'EmployeeClockedIn', ['audience' => 'tenant'], $now);
        $registry->register($alpha->id(), 'email', 'EmployeeClockedIn', ['to' => 'ops@example.com'], $now);
        $registry->register($alpha->id(), 'queue', 'EmployeeClockedIn', ['name' => 'attendance'], $now);
        $registry->register($beta->id(), 'internal', 'EmployeeClockedIn', ['handler' => 'audit'], $now);

        $subjectA = Uuid::v7()->toRfc4122();
        $subjectB = Uuid::v7()->toRfc4122();
        $switcher = $this->switcher();
        $switcher->switchTo($alpha->id());
        $this->publisher()->publish('EmployeeClockedIn', $subjectA, [
            'employeeId' => $subjectA,
            'action' => 'clock_in',
        ], $now);
        $switcher->switchTo($beta->id());
        $this->publisher()->publish('EmployeeClockedIn', $subjectB, [
            'employeeId' => $subjectB,
            'action' => 'clock_in',
        ], $now);
        $switcher->reset();
        $this->drain();

        $switcher->switchTo($alpha->id());
        $alphaAudit = $this->joined("SELECT metadata FROM audit_log WHERE action = 'integration.internal'");
        self::assertStringContainsString($subjectA, $alphaAudit);
        self::assertStringNotContainsString($subjectB, $alphaAudit);
        self::assertSame([$subjectA], $this->column('SELECT subject_id FROM integration_notifications'));
        self::assertSame(['ops@example.com'], $this->column('SELECT recipient FROM integration_outbox'));
        self::assertNotContains($beta->id(), $this->column('SELECT tenant_id FROM integration_deliveries'));

        $switcher->switchTo($beta->id());
        $betaAudit = $this->joined("SELECT metadata FROM audit_log WHERE action = 'integration.internal'");
        self::assertStringContainsString($subjectB, $betaAudit);
        self::assertStringNotContainsString($subjectA, $betaAudit);
        self::assertSame([], $this->column('SELECT subject_id FROM integration_notifications'));
        self::assertSame([], $this->column('SELECT recipient FROM integration_outbox'));
        self::assertNotContains($alpha->id(), $this->column('SELECT tenant_id FROM integration_deliveries'));
        $switcher->reset();
    }

    public function testAForgedMessageCannotWriteTheOtherTenantDatabase(): void
    {
        self::bootKernel();
        $suffix = bin2hex(random_bytes(3));
        $alpha = $this->provision('frga'.$suffix, 'frga'.$suffix.'.localhost');
        $beta = $this->provision('frgb'.$suffix, 'frgb'.$suffix.'.localhost');
        $eventId = Uuid::v7()->toRfc4122();
        $message = new TenantDomainEventMessage(
            $eventId,
            $beta->id(),
            'EmployeeClockedOut',
            '2026-10-08T16:00:00Z',
            Uuid::v7()->toRfc4122(),
            ['employeeId' => Uuid::v7()->toRfc4122()],
        );
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(SenderInterface::class, $transport);
        $transport->send(new Envelope($message, [new TenantStamp($alpha->id())]));
        $this->drain();

        $switcher = $this->switcher();
        $switcher->switchTo($beta->id());
        self::assertSame([], $this->column('SELECT event_id FROM integration_deliveries WHERE event_id = '.$this->quote($eventId)));
        self::assertSame([], $this->column('SELECT target_id FROM audit_log WHERE target_id = '.$this->quote($eventId)));

        $switcher->switchTo($alpha->id());
        self::assertContains('dead', $this->column('SELECT status FROM integration_deliveries WHERE event_id = '.$this->quote($eventId)));
        self::assertSame([$beta->id()], $this->column('SELECT tenant_id FROM integration_deliveries WHERE event_id = '.$this->quote($eventId)));
        $switcher->reset();
    }

    public function testFailedWebhooksAreRetriedAndDeadLetteredInTheStampedTenant(): void
    {
        self::bootKernel();
        $suffix = bin2hex(random_bytes(3));
        $alpha = $this->provision('wha'.$suffix, 'wha'.$suffix.'.localhost');
        $beta = $this->provision('whb'.$suffix, 'whb'.$suffix.'.localhost');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->registry()->register($alpha->id(), 'webhook', 'EmployeeClockedOut', [
            'url' => 'https://hooks.example.com/attendance',
        ], $now);
        $client = self::getContainer()->get(RecordingWebhookClient::class);
        self::assertInstanceOf(RecordingWebhookClient::class, $client);
        $client->fail = true;

        $subject = Uuid::v7()->toRfc4122();
        $switcher = $this->switcher();
        $switcher->switchTo($alpha->id());
        $this->publisher()->publish('EmployeeClockedOut', $subject, ['employeeId' => $subject], $now);
        $switcher->reset();
        $this->drain();

        $switcher->switchTo($alpha->id());
        $statuses = $this->column('SELECT status FROM integration_deliveries');
        self::assertContains('failed', $statuses);
        self::assertContains('dead', $statuses);
        self::assertNotSame([], $this->column("SELECT id FROM audit_log WHERE action = 'integration.delivery_failed'"));
        self::assertNotSame([], $this->column("SELECT id FROM audit_log WHERE action = 'integration.delivery_dead'"));

        $switcher->switchTo($beta->id());
        self::assertSame([], $this->column('SELECT id FROM integration_deliveries'));
        self::assertSame([], $this->column("SELECT id FROM audit_log WHERE action LIKE 'integration.%'"));

        $client->fail = false;
        $client->clear();
        $switcher->switchTo($alpha->id());
        $this->publisher()->publish('EmployeeClockedOut', $subject, ['employeeId' => $subject], $now);
        $switcher->reset();
        $this->drain();
        $body = $client->latestBody();
        self::assertStringContainsString($alpha->id(), $body);
        self::assertStringNotContainsString($beta->id(), $body);
        $switcher->switchTo($alpha->id());
        self::assertContains('delivered', $this->column('SELECT status FROM integration_deliveries'));
        $switcher->reset();
    }

    private function provision(string $slug, string $hostname): Tenant
    {
        $provision = self::getContainer()->get(ProvisionTenant::class);
        self::assertInstanceOf(ProvisionTenant::class, $provision);

        return $provision->execute(new ProvisionTenantRequest($slug, 'Example AB', $hostname, 'Europe/Stockholm', 'sv', 'cli'));
    }

    private function registry(): TenantIntegrationRegistry
    {
        $registry = self::getContainer()->get(TenantIntegrationRegistry::class);
        self::assertInstanceOf(TenantIntegrationRegistry::class, $registry);

        return $registry;
    }

    private function publisher(): DomainEventPublisher
    {
        $publisher = self::getContainer()->get(DomainEventPublisher::class);
        self::assertInstanceOf(DomainEventPublisher::class, $publisher);

        return $publisher;
    }

    private function switcher(): TenantDatabaseSwitcher
    {
        $switcher = self::getContainer()->get(TenantDatabaseSwitcher::class);
        self::assertInstanceOf(TenantDatabaseSwitcher::class, $switcher);

        return $switcher;
    }

    private function connection(): TenantAwareConnection
    {
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        self::assertInstanceOf(TenantAwareConnection::class, $connection);

        return $connection;
    }

    private function transport(): ReceiverInterface
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(ReceiverInterface::class, $transport);

        return $transport;
    }

    private function drain(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $bus = self::getContainer()->get('messenger.default_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $passes = 0;
        $listener = static function (WorkerRunningEvent $event) use (&$passes): void {
            ++$passes;
            if ($event->isWorkerIdle() || $passes > 40) {
                $event->getWorker()->stop();
            }
        };
        $dispatcher->addListener(WorkerRunningEvent::class, $listener);
        try {
            (new Worker(['async' => $this->transport()], $bus, $dispatcher))->run([
                'sleep' => 0,
                'time_limit' => 5,
            ]);
        } finally {
            $dispatcher->removeListener(WorkerRunningEvent::class, $listener);
        }
    }

    /**
     * @return list<string>
     */
    private function column(string $sql): array
    {
        $values = [];
        foreach ($this->connection()->fetchFirstColumn($sql) as $value) {
            self::assertIsString($value);
            $values[] = $value;
        }

        return $values;
    }

    private function joined(string $sql): string
    {
        return implode("\n", $this->column($sql));
    }

    private function quote(string $value): string
    {
        return $this->connection()->quote($value);
    }
}
