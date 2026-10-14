<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Audit\AuditMetadata;
use PHPUnit\Framework\TestCase;

final class AuditMetadataTest extends TestCase
{
    public function testSecretsAreRemovedAndTheResultFollowsTheAction(): void
    {
        $clean = AuditMetadata::sanitize([
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'privateKey' => 'secret-key',
            'accessToken' => 'session-token',
            'fingerprint' => 'raw-sample',
            'note' => "ok\nline",
            'blob' => '-----BEGIN PRIVATE KEY-----',
        ]);

        self::assertSame(['email' => 'ada@example.com', 'note' => 'okline'], $clean);
        self::assertSame('failure', AuditMetadata::result('auth.login_failed', []));
        self::assertSame('success', AuditMetadata::result('employee.created', []));
        self::assertSame('failure', AuditMetadata::result('employee.created', ['result' => 'failure']));
    }
}
