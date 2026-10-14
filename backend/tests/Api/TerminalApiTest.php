<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Identity\Application\OpenAccounts;
use App\Platform\Device\DeviceSignature;
use App\Platform\TenantRegistry\Application\DeviceEnrollment;
use App\Platform\TenantRegistry\Application\DeviceKeyPublisher;
use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Tenant\Directory\AttendanceIdentifierIssuer;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class TerminalApiTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    public function testTheServerIdentifiesTheEmployeeAndChoosesTheAttendanceAction(): void
    {
        [$client, $host] = $this->openTenant('kiosk');
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $deviceId = $this->enroll($host, $secret, 'Europe/Stockholm');
        $code = $this->employee($host, 'Ada Terminal');
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\xFF\xD9";

        $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts', json_encode(['code' => $code], JSON_THROW_ON_ERROR), null, '');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('unauthenticated', $this->problem($client)['code']);

        $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts', json_encode(['code' => $code], JSON_THROW_ON_ERROR), '01a10000-0000-7000-8000-000000000001', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        self::assertResponseStatusCodeSame(401);
        self::assertSame('device_rejected', $this->problem($client)['code']);

        $unknown = $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts', json_encode(['code' => 'AAAAAAAAAA'], JSON_THROW_ON_ERROR), $deviceId, $secret);
        self::assertSame(422, $unknown['status']);
        self::assertSame('unknown_code', $unknown['code']);
        self::assertStringNotContainsString('AAAAAAAAAA', (string) json_encode($unknown));

        $this->accounts()->tenantUser($this->slug($host), 'ada-kiosk@example.com', 'Ada', self::PASSWORD, ['employee']);
        $this->login($client, $host, 'ada-kiosk@example.com');
        $this->call($client, 'POST', $host, '/api/v1/terminal/attempts', ['code' => $code, 'action' => 'clock_out']);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('unauthenticated', $this->problem($client)['code']);

        $opened = $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts', json_encode([
            'code' => $code,
            'action' => 'clock_out',
            'occurredAt' => '1999-01-01T00:00:00Z',
        ], JSON_THROW_ON_ERROR), $deviceId, $secret);
        self::assertResponseIsSuccessful();
        $attempt = $opened['data'];
        self::assertSame('Ada Terminal', $attempt['employee']['displayName']);
        self::assertArrayNotHasKey('allowedActions', $attempt);
        self::assertStringNotContainsString($code, (string) json_encode($opened));

        $rejected = $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts/'.$attempt['attemptId'].'/fingerprint', json_encode([
            'vendor' => 'mock',
            'sample' => 'dev-reject',
        ], JSON_THROW_ON_ERROR), $deviceId, $secret);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('fingerprint_mismatch', $rejected['code']);

        $verified = $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts/'.$attempt['attemptId'].'/fingerprint', json_encode([
            'vendor' => 'mock',
            'sample' => 'dev-verify',
        ], JSON_THROW_ON_ERROR), $deviceId, $secret);
        self::assertResponseIsSuccessful();
        self::assertSame('off', $verified['data']['state']);
        self::assertSame([['action' => 'clock_in', 'photoRequired' => true]], $verified['data']['allowedActions']);

        $badPhoto = $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts/'.$attempt['attemptId'].'/photo', 'not-a-photo', $deviceId, $secret, [
            'CONTENT_TYPE' => 'image/jpeg',
        ]);
        self::assertSame(422, $badPhoto['status']);
        self::assertSame('photo_invalid', $badPhoto['code']);

        $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts/'.$attempt['attemptId'].'/commit', json_encode([
            'action' => 'clock_in',
        ], JSON_THROW_ON_ERROR), $deviceId, $secret, ['HTTP_IDEMPOTENCY_KEY' => 'clock-in-without-photo']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('photo_required', $this->problem($client)['code']);

        $photo = $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts/'.$attempt['attemptId'].'/photo', $jpeg, $deviceId, $secret, [
            'CONTENT_TYPE' => 'image/jpeg',
        ]);
        self::assertResponseIsSuccessful();
        self::assertIsArray($photo['data']);
        self::assertArrayHasKey('photoId', $photo['data']);
        self::assertArrayNotHasKey('storageKey', $photo['data']);
        self::assertStringNotContainsString('var/photos', (string) json_encode($photo));
        $recorded = $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts/'.$attempt['attemptId'].'/commit', json_encode([
            'action' => 'clock_out',
            'occurredAt' => '1999-01-01T00:00:00Z',
        ], JSON_THROW_ON_ERROR), $deviceId, $secret, ['HTTP_IDEMPOTENCY_KEY' => 'clock-out-while-off']);
        self::assertSame(409, $recorded['status']);
        self::assertSame('illegal_transition', $recorded['code']);

        $clockIn = $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts/'.$attempt['attemptId'].'/commit', json_encode([
            'action' => 'clock_in',
            'occurredAt' => '1999-01-01T00:00:00Z',
        ], JSON_THROW_ON_ERROR), $deviceId, $secret, ['HTTP_IDEMPOTENCY_KEY' => 'clock-in-with-photo']);
        self::assertSame(200, $clockIn['status']);
        self::assertSame('clock_in', $clockIn['data']['action']);
        self::assertSame('working', $clockIn['data']['state']);
        self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/', $clockIn['data']['occurredAt']);
        self::assertNotSame('1999-01-01T00:00:00Z', $clockIn['data']['occurredAt']);

        $replay = $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts/'.$attempt['attemptId'].'/commit', json_encode([
            'action' => 'clock_in',
            'occurredAt' => '1999-01-01T00:00:00Z',
        ], JSON_THROW_ON_ERROR), $deviceId, $secret, ['HTTP_IDEMPOTENCY_KEY' => 'clock-in-with-photo']);
        self::assertSame(200, $replay['status']);
        self::assertTrue($replay['data']['replayed']);
        self::assertSame($clockIn['data']['eventId'], $replay['data']['eventId']);

        $lunch = $this->commitAction($client, $host, $deviceId, $secret, $code, 'lunch_start', 'lunch-start-01');
        self::assertSame('lunch_start', $lunch['data']['action']);
        self::assertSame('on_lunch', $lunch['data']['state']);
        $back = $this->commitAction($client, $host, $deviceId, $secret, $code, 'lunch_end', 'lunch-end-01');
        self::assertSame('lunch_end', $back['data']['action']);
        self::assertSame('working', $back['data']['state']);
        $leave = $this->commitAction($client, $host, $deviceId, $secret, $code, 'clock_out', 'clock-out-after-work');
        self::assertSame('clock_out', $leave['data']['action']);
        self::assertSame('off', $leave['data']['state']);
        self::assertNotSame($clockIn['data']['eventId'], $lunch['data']['eventId']);
        self::assertNotSame($lunch['data']['eventId'], $back['data']['eventId']);
        self::assertNotSame($back['data']['eventId'], $leave['data']['eventId']);

        [, $otherHost] = $this->openTenant('other', $client);
        $foreign = $this->signed($client, 'POST', $otherHost, '/api/v1/terminal/attempts', json_encode(['code' => $code], JSON_THROW_ON_ERROR), $deviceId, $secret);
        self::assertSame(403, $foreign['status']);
        self::assertSame('tenant_mismatch', $foreign['code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function commitAction(KernelBrowser $client, string $host, string $deviceId, string $secret, string $code, string $action, string $key): array
    {
        $opened = $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts', json_encode(['code' => $code], JSON_THROW_ON_ERROR), $deviceId, $secret);
        $attemptId = $opened['data']['attemptId'];
        self::assertIsString($attemptId);
        $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts/'.$attemptId.'/fingerprint', json_encode([
            'vendor' => 'mock',
            'sample' => 'dev-verify',
        ], JSON_THROW_ON_ERROR), $deviceId, $secret);

        return $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts/'.$attemptId.'/commit', json_encode([
            'action' => $action,
        ], JSON_THROW_ON_ERROR), $deviceId, $secret, ['HTTP_IDEMPOTENCY_KEY' => $key]);
    }

    private function enroll(string $host, string $secret, string $timezone): string
    {
        $enrollment = self::getContainer()->get(DeviceEnrollment::class);
        $publisher = self::getContainer()->get(DeviceKeyPublisher::class);
        self::assertInstanceOf(DeviceEnrollment::class, $enrollment);
        self::assertInstanceOf(DeviceKeyPublisher::class, $publisher);
        $device = $enrollment->register($this->slug($host), 'Front desk', 'cli');
        $public = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
        $publisher->publish($device->id(), $public, $timezone, 'cli');
        $enrollment->activate($device->id(), 'cli');

        return $device->id();
    }

    private function employee(string $host, string $name): string
    {
        $switcher = self::getContainer()->get(TenantDatabaseSwitcher::class);
        $issuer = self::getContainer()->get(AttendanceIdentifierIssuer::class);
        self::assertInstanceOf(TenantDatabaseSwitcher::class, $switcher);
        self::assertInstanceOf(AttendanceIdentifierIssuer::class, $issuer);
        $registry = self::getContainer()->get(\App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore::class);
        self::assertInstanceOf(\App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore::class, $registry);
        $tenant = $registry->findTenantBySlug($this->slug($host));
        self::assertNotNull($tenant);
        $switcher->switchTo($tenant->id());
        try {
            $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
            self::assertInstanceOf(Connection::class, $connection);
            $id = Uuid::v7()->toRfc4122();
            $issued = $issuer->issue();
            $connection->insert('employees', [
                'id' => $id,
                'employee_number' => 'KIOSK01',
                'display_name' => $name,
                'status' => 'active',
                'created_at' => '2026-10-07 06:00:00',
                'updated_at' => '2026-10-07 06:00:00',
            ]);
            $connection->insert('employee_codes', [
                'employee_id' => $id,
                'code' => $issued['hash'],
                'created_at' => '2026-10-07 06:00:00',
            ]);

            return $issued['code'];
        } finally {
            $switcher->reset();
        }
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    private function signed(KernelBrowser $client, string $method, string $host, string $path, string $body, ?string $deviceId, string $secret, array $server = []): array
    {
        $server['HTTP_HOST'] = $host;
        $server['HTTP_ACCEPT'] = 'application/json';
        $server['CONTENT_TYPE'] ??= 'application/json';
        if ($deviceId !== null && $secret !== '') {
            $timestamp = (string) time();
            $nonce = str_replace('-', '', Uuid::v7()->toRfc4122());
            $payload = DeviceSignature::payload($timestamp, $nonce, $method, parse_url('http://'.$host.$path, PHP_URL_PATH) ?: $path, DeviceSignature::bodyHash($body));
            $server['HTTP_X_DEVICE_ID'] = $deviceId;
            $server['HTTP_X_DEVICE_TIMESTAMP'] = $timestamp;
            $server['HTTP_X_DEVICE_NONCE'] = $nonce;
            $server['HTTP_X_DEVICE_SIGNATURE'] = base64_encode(sodium_crypto_sign_detached($payload, $secret));
        }
        $client->request($method, 'http://'.$host.$path, server: $server, content: $body);
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);
        $decoded['status'] = $client->getResponse()->getStatusCode();

        return $decoded;
    }

    /**
     * @return array{0: KernelBrowser, 1: string}
     */
    private function openTenant(string $prefix, ?KernelBrowser $client = null): array
    {
        $client ??= self::createClient();
        $suffix = bin2hex(random_bytes(3));
        $host = $prefix.$suffix.'.localhost';
        $provision = self::getContainer()->get(ProvisionTenant::class);
        self::assertInstanceOf(ProvisionTenant::class, $provision);
        $provision->execute(new ProvisionTenantRequest($prefix.$suffix, 'Example AB', $host, 'Europe/Stockholm', 'en', 'cli'));

        return [$client, $host];
    }

    private function accounts(): OpenAccounts
    {
        $accounts = self::getContainer()->get(OpenAccounts::class);
        self::assertInstanceOf(OpenAccounts::class, $accounts);

        return $accounts;
    }

    private function slug(string $host): string
    {
        return explode('.', $host, 2)[0];
    }

    private function login(KernelBrowser $client, string $host, string $email): void
    {
        $client->getCookieJar()->clear();
        $this->call($client, 'POST', $host, '/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(KernelBrowser $client, string $method, string $host, string $path, ?array $body = null): void
    {
        $server = ['HTTP_HOST' => $host, 'HTTP_ACCEPT' => 'application/json'];
        $content = null;
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($body, JSON_THROW_ON_ERROR);
        }
        $client->request($method, 'http://'.$host.$path, server: $server, content: $content);
    }

    /**
     * @return array<string, mixed>
     */
    private function problem(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
