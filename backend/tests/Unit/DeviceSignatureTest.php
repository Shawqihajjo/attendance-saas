<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\Device\DeviceSignature;
use App\Tenant\Attendance\AllowedAttendanceActions;
use App\Tenant\Attendance\AttendanceState;
use App\Hardware\Fingerprint\BiometricClaim;
use App\Hardware\Fingerprint\MockFingerprintAdapter;
use PHPUnit\Framework\TestCase;

final class DeviceSignatureTest extends TestCase
{
    public function testASignedPayloadVerifiesWithThePublicKey(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $payload = DeviceSignature::payload('1700000000', 'nonce-1234', 'post', '/api/v1/terminal/attempts', DeviceSignature::bodyHash('{"code":"ABCD"}'));
        $signature = base64_encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair)));
        $publicKey = base64_encode(sodium_crypto_sign_publickey($pair));

        self::assertTrue(DeviceSignature::verify($payload, $signature, $publicKey));
        self::assertFalse(DeviceSignature::verify($payload.' ', $signature, $publicKey));
        self::assertFalse(DeviceSignature::verify($payload, $signature, base64_encode(random_bytes(32))));
    }

    public function testAllowedActionsComeFromTheAttendanceState(): void
    {
        self::assertSame([
            ['action' => 'clock_in', 'photoRequired' => true],
        ], AllowedAttendanceActions::forState(AttendanceState::Off, true));
        self::assertSame([
            ['action' => 'lunch_start', 'photoRequired' => false],
            ['action' => 'clock_out', 'photoRequired' => false],
        ], AllowedAttendanceActions::forState(AttendanceState::Working, true));
        self::assertSame([
            ['action' => 'lunch_end', 'photoRequired' => false],
        ], AllowedAttendanceActions::forState(AttendanceState::OnLunch, false));
    }

    public function testTheMockMatcherRejectsProductionAndUnknownSamples(): void
    {
        $matcher = new MockFingerprintAdapter('dev');
        $accepted = $matcher->verify('employee', new BiometricClaim('mock', 'dev-verify'));
        self::assertTrue($accepted->accepted);
        self::assertFalse($matcher->verify('employee', new BiometricClaim('mock', 'dev-reject'))->accepted);
        self::assertFalse($matcher->verify('employee', new BiometricClaim('vendor', 'dev-verify'))->accepted);

        $this->expectException(\RuntimeException::class);
        new MockFingerprintAdapter('prod');
    }
}
