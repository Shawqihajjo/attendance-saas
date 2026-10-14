<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Logging\SecretRedactingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class SecretRedactingProcessorTest extends TestCase
{
    public function testUserinfoInAMessageOrContextIsRemoved(): void
    {
        $record = new LogRecord(
            new \DateTimeImmutable('2026-10-08T08:00:00Z'),
            'app',
            Level::Error,
            'Connection mysql://attendance:attendance@mysql:3306/platform failed',
            [
                'dsn' => 'amqp://attendance:attendance@rabbitmq:5672/%2f',
                'nested' => ['redis' => 'redis://:attendance@redis:6379'],
            ],
        );

        $processed = (new SecretRedactingProcessor())($record);

        self::assertStringNotContainsString('attendance@', $processed->message);
        self::assertStringNotContainsString('attendance:attendance', $processed->message);
        self::assertSame('mysql://***:***@mysql:3306/platform failed', strstr($processed->message, 'mysql:'));
        $dsn = $processed->context['dsn'];
        self::assertIsString($dsn);
        self::assertSame('amqp://***:***@rabbitmq:5672/%2f', $dsn);
        $nested = $processed->context['nested'];
        self::assertIsArray($nested);
        self::assertSame('redis://***:***@redis:6379', $nested['redis']);
    }
}
