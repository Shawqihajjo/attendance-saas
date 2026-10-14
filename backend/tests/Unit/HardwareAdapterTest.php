<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Hardware\BiometricLogProcessor;
use App\Hardware\Camera\MockCameraAdapter;
use App\Hardware\Camera\UnavailableCamera;
use App\Hardware\DeviceAvailability;
use App\Hardware\EnrollmentStatus;
use App\Hardware\Fingerprint\BiometricClaim;
use App\Hardware\Fingerprint\MockFingerprintAdapter;
use App\Hardware\Fingerprint\UnavailableFingerprintReader;
use App\Hardware\HardwareError;
use App\Hardware\HardwareException;
use App\Tenant\Attendance\Infrastructure\HardwareFingerprintMatcher;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class HardwareAdapterTest extends TestCase
{
    public function testTheMockFingerprintAdapterAcceptsOnlyTheDevelopmentSample(): void
    {
        $adapter = new MockFingerprintAdapter('test');
        $claim = new BiometricClaim('mock', 'dev-verify');
        $accepted = $adapter->verify('employee-1', $claim);

        self::assertTrue($accepted->accepted);
        self::assertNull($accepted->error);
        self::assertSame(EnrollmentStatus::Unknown, $adapter->enrollmentStatus('employee-1'));
        self::assertSame(DeviceAvailability::Available, $adapter->availability());
        self::assertSame('healthy', $adapter->health()->status);
        self::assertSame(
            ['vendor' => 'mock', 'byteLength' => strlen('dev-verify')],
            json_decode((string) json_encode($claim), true),
        );
        self::assertStringNotContainsString('dev-verify', (string) json_encode($claim));
        self::assertSame(HardwareError::FingerprintMismatch, $adapter->verify('employee-1', new BiometricClaim('mock', 'other'))->error);
    }

    public function testScriptedFaultsStayOnTheErrorCode(): void
    {
        $adapter = new MockFingerprintAdapter('test', HardwareError::Timeout);
        $result = $adapter->verify('employee-1', new BiometricClaim('mock', 'dev-verify'));

        self::assertFalse($result->accepted);
        self::assertSame(HardwareError::Timeout, $result->error);
        self::assertSame(HardwareError::Timeout, $adapter->health()->error);

        $disconnected = new MockFingerprintAdapter('test', HardwareError::DeviceDisconnected);
        self::assertSame(DeviceAvailability::Disconnected, $disconnected->availability());
        self::assertSame(HardwareError::HardwareCommunicationFailure, (new MockFingerprintAdapter('test', HardwareError::HardwareCommunicationFailure))->verify('employee-1', new BiometricClaim('mock', 'dev-verify'))->error);
    }

    public function testTheUnavailableReaderDoesNotAcceptASample(): void
    {
        $reader = new UnavailableFingerprintReader();
        $result = $reader->verify('employee-1', new BiometricClaim('vendor', 'secret-sample'));

        self::assertFalse($result->accepted);
        self::assertSame(HardwareError::DeviceUnavailable, $result->error);
        self::assertSame(DeviceAvailability::Unavailable, $reader->availability());
        self::assertSame('device_unavailable', (new HardwareFingerprintMatcher($reader))->verify('employee-1', 'vendor', 'secret-sample')->reason);
    }

    public function testTheMockCameraReturnsBytesWithoutPuttingThemInJson(): void
    {
        $camera = new MockCameraAdapter();
        $photo = $camera->capture('attempt-1');

        self::assertSame('image/jpeg', $photo->contentType);
        self::assertStringStartsWith("\xFF\xD8", $photo->bytes());
        self::assertSame(
            ['contentType' => 'image/jpeg', 'byteLength' => strlen($photo->bytes())],
            json_decode((string) json_encode($photo), true),
        );
        self::assertStringNotContainsString($photo->bytes(), (string) json_encode($photo));

        $failed = new MockCameraAdapter(HardwareError::CaptureFailed);
        try {
            $failed->capture('attempt-1');
            self::fail('The scripted camera fault should be reported.');
        } catch (HardwareException $exception) {
            self::assertSame(HardwareError::CaptureFailed, $exception->error);
            self::assertStringNotContainsString('JFIF', $exception->getMessage());
        }

        $unavailable = new UnavailableCamera();
        $this->expectException(HardwareException::class);
        $unavailable->capture('attempt-1');
    }

    public function testApplicationLogsDropBiometricPayloads(): void
    {
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'app',
            Level::Error,
            'verification failed',
            [
                'sample' => 'dev-verify',
                'employeeId' => 'employee-1',
                'nested' => ['template' => 'raw-template'],
                'claim' => new BiometricClaim('mock', 'dev-verify'),
            ],
        );
        $processed = (new BiometricLogProcessor())($record);
        $encoded = (string) json_encode($processed->context);

        self::assertSame('[redacted]', $processed->context['sample']);
        self::assertSame('employee-1', $processed->context['employeeId']);
        self::assertSame('[redacted]', $processed->context['nested']['template']);
        self::assertStringNotContainsString('dev-verify', $encoded);
        self::assertStringNotContainsString('raw-template', $encoded);
    }
}
