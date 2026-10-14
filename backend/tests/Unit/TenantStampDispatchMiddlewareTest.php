<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Integration\TenantDomainEventMessage;
use App\TenantContext\Domain\TenantContext;
use App\TenantContext\Domain\TenantContextHolder;
use App\TenantContext\Domain\TenantStampMismatchException;
use App\TenantContext\Infrastructure\Messenger\TenantStamp;
use App\TenantContext\Infrastructure\Messenger\TenantStampDispatchMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Uid\Uuid;

final class TenantStampDispatchMiddlewareTest extends TestCase
{
    public function testActiveContextStampsTheEnvelope(): void
    {
        $holder = new TenantContextHolder();
        $tenantId = Uuid::v7()->toRfc4122();
        $holder->set(new TenantContext($tenantId, 'alpha', 'Europe/Stockholm', 'en', ['en', 'sv'], true));
        $middleware = new TenantStampDispatchMiddleware($holder);
        $seen = new EnvelopeBox();

        $middleware->handle(new Envelope(new \stdClass()), $this->stack($seen));

        $stamp = $seen->envelope?->last(TenantStamp::class);
        self::assertInstanceOf(TenantStamp::class, $stamp);
        self::assertSame($tenantId, $stamp->tenantId);
    }

    public function testMismatchedStampIsRejected(): void
    {
        $holder = new TenantContextHolder();
        $holder->set(new TenantContext(Uuid::v7()->toRfc4122(), 'alpha', 'Europe/Stockholm', 'en', ['en', 'sv'], true));
        $middleware = new TenantStampDispatchMiddleware($holder);

        $this->expectException(TenantStampMismatchException::class);
        $middleware->handle(
            new Envelope(new \stdClass(), [new TenantStamp(Uuid::v7()->toRfc4122())]),
            $this->stack(new EnvelopeBox()),
        );
    }

    public function testADomainEventRejectsNonScalarPayloads(): void
    {
        $ids = [Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122()];
        foreach ([['employee-1'], ['employeeId' => ['nested']]] as $payload) {
            try {
                new TenantDomainEventMessage($ids[0], $ids[1], 'EmployeeClockedIn', '2026-10-08T08:00:00Z', $ids[2], $payload);
                self::fail('A non-scalar payload must be rejected.');
            } catch (\InvalidArgumentException) {
            }
        }
        $message = new TenantDomainEventMessage($ids[0], $ids[1], 'EmployeeClockedIn', '2026-10-08T08:00:00Z', $ids[2], ['employeeId' => $ids[1]]);
        self::assertSame($ids[1], $message->payload['employeeId']);
    }

    public function testADomainEventCannotClaimAnotherTenant(): void
    {
        $holder = new TenantContextHolder();
        $alpha = Uuid::v7()->toRfc4122();
        $beta = Uuid::v7()->toRfc4122();
        $holder->set(new TenantContext($alpha, 'alpha', 'Europe/Stockholm', 'en', ['en', 'sv'], true));
        $middleware = new TenantStampDispatchMiddleware($holder);
        $message = new TenantDomainEventMessage(
            Uuid::v7()->toRfc4122(),
            $beta,
            'EmployeeClockedIn',
            '2026-10-08T08:00:00Z',
            Uuid::v7()->toRfc4122(),
            ['employeeId' => $beta],
        );

        $this->expectException(TenantStampMismatchException::class);
        $middleware->handle(new Envelope($message, [new TenantStamp($beta)]), $this->stack(new EnvelopeBox()));
    }

    private function stack(EnvelopeBox $seen): StackInterface
    {
        return new class($seen) implements StackInterface {
            public function __construct(private EnvelopeBox $seen)
            {
            }

            public function next(): MiddlewareInterface
            {
                return new class($this->seen) implements MiddlewareInterface {
                    public function __construct(private EnvelopeBox $seen)
                    {
                    }

                    public function handle(Envelope $envelope, StackInterface $stack): Envelope
                    {
                        $this->seen->envelope = $envelope;

                        return $envelope;
                    }
                };
            }
        };
    }
}

final class EnvelopeBox
{
    public ?Envelope $envelope = null;
}
