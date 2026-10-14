<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tenant\Directory\AttendanceIdentifierIssuer;
use PHPUnit\Framework\TestCase;

final class AttendanceIdentifierTest extends TestCase
{
    public function testIssuedCodeIsHashedAndNotTheStoredValue(): void
    {
        $issuer = new AttendanceIdentifierIssuer('test-secret');
        $issued = $issuer->issue();

        self::assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{2}$/', $issued['code']);
        self::assertSame(64, strlen($issued['hash']));
        self::assertNotSame($issued['code'], $issued['hash']);
        self::assertSame($issued['hash'], $issuer->hash($issued['code']));
        self::assertSame($issued['hash'], $issuer->hash(str_replace('-', '', $issued['code'])));
        self::assertNotSame($issuer->hash('ABCD-EFGH-23'), $issuer->hash('ABCD-EFGH-24'));
    }
}
