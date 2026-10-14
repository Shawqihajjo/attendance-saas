<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\Device\IpRange;
use PHPUnit\Framework\TestCase;

final class IpRangeTest extends TestCase
{
    public function testCidrMatchingIgnoresSpoofableIdentifiers(): void
    {
        self::assertTrue(IpRange::contains('10.1.2.9', '10.1.2.0/24'));
        self::assertFalse(IpRange::contains('10.1.3.9', '10.1.2.0/24'));
        self::assertFalse(IpRange::contains('10.1.2.9', 'not-a-range'));
        self::assertTrue(IpRange::contains('2001:db8::1', '2001:db8::/32'));
        self::assertFalse(IpRange::valid('AA:BB:CC:DD:EE:FF'));
    }
}
