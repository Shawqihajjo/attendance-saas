<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\TenantContext\Domain\TenantDatabaseSwitcher;
use App\TenantContext\Infrastructure\Messenger\TenantStamp;
use App\TenantContext\Infrastructure\Messenger\TenantSwitchMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

final class TenantSwitchMiddlewareTest extends TestCase
{
    public function testDispatchDoesNotResetTheCurrentConnection(): void
    {
        $switcher = new RecordingSwitcher();
        $middleware = new TenantSwitchMiddleware($switcher);
        $flag = new HandlerFlag();

        $middleware->handle(new Envelope(new \stdClass()), $this->stack($flag));

        self::assertTrue($flag->handled);
        self::assertSame([], $switcher->calls);
    }

    public function testReceivedMessageSwitchesAndResets(): void
    {
        $switcher = new RecordingSwitcher();
        $middleware = new TenantSwitchMiddleware($switcher);
        $flag = new HandlerFlag();
        $tenantId = Uuid::v7()->toRfc4122();

        $middleware->handle(
            new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TenantStamp($tenantId)]),
            $this->stack($flag),
        );

        self::assertTrue($flag->handled);
        self::assertSame(['reset', 'switch:'.$tenantId, 'reset'], $switcher->calls);
    }

    public function testResetRunsWhenTheHandlerThrows(): void
    {
        $switcher = new RecordingSwitcher();
        $middleware = new TenantSwitchMiddleware($switcher);
        $flag = new HandlerFlag(throw: true);

        try {
            $middleware->handle(
                new Envelope(new \stdClass(), [new ReceivedStamp('async')]),
                $this->stack($flag),
            );
            self::fail('The handler exception should bubble.');
        } catch (\RuntimeException $exception) {
            self::assertSame('handler failed', $exception->getMessage());
        }

        self::assertSame(['reset', 'reset'], $switcher->calls);
    }

    public function testAFailedSwitchStillClearsTheConnection(): void
    {
        $switcher = new RecordingSwitcher(throwOnSwitch: true);
        $middleware = new TenantSwitchMiddleware($switcher);
        $tenantId = Uuid::v7()->toRfc4122();

        try {
            $middleware->handle(
                new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TenantStamp($tenantId)]),
                $this->stack(new HandlerFlag()),
            );
            self::fail('The switch exception should bubble.');
        } catch (\RuntimeException $exception) {
            self::assertSame('switch failed', $exception->getMessage());
        }

        self::assertSame(['reset', 'switch:'.$tenantId, 'reset'], $switcher->calls);
    }

    public function testTheNextMessageDoesNotKeepThePreviousTenant(): void
    {
        $switcher = new RecordingSwitcher();
        $middleware = new TenantSwitchMiddleware($switcher);
        $first = Uuid::v7()->toRfc4122();
        $second = Uuid::v7()->toRfc4122();

        $middleware->handle(
            new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TenantStamp($first)]),
            $this->stack(new HandlerFlag()),
        );
        $middleware->handle(
            new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TenantStamp($second)]),
            $this->stack(new HandlerFlag()),
        );

        self::assertSame([
            'reset',
            'switch:'.$first,
            'reset',
            'reset',
            'switch:'.$second,
            'reset',
        ], $switcher->calls);
    }

    private function stack(HandlerFlag $flag): StackInterface
    {
        return new class($flag) implements StackInterface {
            public function __construct(private HandlerFlag $flag)
            {
            }

            public function next(): MiddlewareInterface
            {
                return new class($this->flag) implements MiddlewareInterface {
                    public function __construct(private HandlerFlag $flag)
                    {
                    }

                    public function handle(Envelope $envelope, StackInterface $stack): Envelope
                    {
                        $this->flag->handled = true;
                        if ($this->flag->throw) {
                            throw new \RuntimeException('handler failed');
                        }

                        return $envelope;
                    }
                };
            }
        };
    }
}

final class RecordingSwitcher implements TenantDatabaseSwitcher
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly bool $throwOnSwitch = false)
    {
    }

    public function switchTo(string $tenantId): void
    {
        $this->calls[] = 'switch:'.$tenantId;
        if ($this->throwOnSwitch) {
            throw new \RuntimeException('switch failed');
        }
    }

    public function switchForMaintenance(string $tenantId): void
    {
        $this->calls[] = 'maintain:'.$tenantId;
    }

    public function reset(): void
    {
        $this->calls[] = 'reset';
    }
}

final class HandlerFlag
{
    public bool $handled = false;

    public function __construct(public bool $throw = false)
    {
    }
}
