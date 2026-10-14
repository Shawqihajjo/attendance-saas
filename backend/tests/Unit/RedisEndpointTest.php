<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Diagnostics\RedisEndpoint;
use PHPUnit\Framework\TestCase;

final class RedisEndpointTest extends TestCase
{
    public function testAPasswordIsTakenFromTheUrlWithoutKeepingTheUserinfoTogether(): void
    {
        $endpoint = RedisEndpoint::fromUrl('redis://:s3cret@redis:6379/0');

        self::assertNotNull($endpoint);
        self::assertSame('redis', $endpoint->host);
        self::assertSame(6379, $endpoint->port);
        self::assertNull($endpoint->username);
        self::assertSame('s3cret', $endpoint->password);
    }

    public function testAnAclUserAndATlsSchemeAreAccepted(): void
    {
        $endpoint = RedisEndpoint::fromUrl('rediss://app:s3cret@redis.internal:6380');

        self::assertNotNull($endpoint);
        self::assertSame('app', $endpoint->username);
        self::assertSame('s3cret', $endpoint->password);
        self::assertSame(6380, $endpoint->port);
    }

    public function testADisabledUrlDoesNotOpenAConnection(): void
    {
        self::assertNull(RedisEndpoint::fromUrl('disabled://'));
        self::assertNull(RedisEndpoint::fromUrl(''));
    }

    public function testAUrlWithoutAHostIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RedisEndpoint::fromUrl('redis://');
    }
}
