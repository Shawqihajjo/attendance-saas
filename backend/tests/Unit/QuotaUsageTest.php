<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\TenantRegistry\Domain\QuotaUsage;
use PHPUnit\Framework\TestCase;

final class QuotaUsageTest extends TestCase
{
    public function testUnlimitedWarningReachedAndOverLimit(): void
    {
        self::assertSame('ok', QuotaUsage::metric(4, null)['level']);
        self::assertNull(QuotaUsage::metric(4, null)['remaining']);
        self::assertSame('ok', QuotaUsage::metric(3, 5)['level']);
        self::assertSame('warning', QuotaUsage::metric(4, 5)['level']);
        self::assertSame('reached', QuotaUsage::metric(5, 5)['level']);
        self::assertFalse(QuotaUsage::metric(5, 5)['overLimit']);
        self::assertSame('over', QuotaUsage::metric(6, 5)['level']);
        self::assertTrue(QuotaUsage::metric(6, 5)['overLimit']);
        self::assertSame(0, QuotaUsage::metric(6, 5)['remaining']);
        self::assertSame('reached', QuotaUsage::metric(0, 0)['level']);
        self::assertSame('unavailable', QuotaUsage::metric(null, 5)['level']);
    }
}
