<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Identity\Application\OpenAccounts;
use App\Platform\Device\DeviceSignature;
use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class DeviceManagementApiTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    public function testRegistrationBindsAKeyAndRejectsNetworkIdentifiersAsCredentials(): void
    {
        [$client, $host] = $this->openTenant('devices');
        $slug = $this->slug($host);
        $this->accounts()->tenantUser($slug, 'admin@example.com', 'Ada', self::PASSWORD, ['tenant_admin']);
        $this->accounts()->tenantUser($slug, 'hr@example.com', 'Bo', self::PASSWORD, ['hr']);
        $this->accounts()->tenantUser($slug, 'employee@example.com', 'Cy', self::PASSWORD, ['employee']);
        $this->login($client, $host, 'employee@example.com');
        $this->call($client, 'GET', $host, '/api/v1/hr/terminals');
        self::assertResponseStatusCodeSame(403);
        $this->login($client, $host, 'hr@example.com');
        $this->call($client, 'POST', $host, '/api/v1/hr/terminals', ['displayName' => 'Side door']);
        self::assertResponseStatusCodeSame(403);

        $this->login($client, $host, 'admin@example.com');
        $created = $this->json($client, 'POST', $host, '/api/v1/hr/terminals', [
            'displayName' => 'Side door',
            'location' => 'Lobby',
            'requireActivation' => false,
        ]);
        self::assertResponseStatusCodeSame(201);
        $token = $created['data']['enrollmentToken'];
        self::assertIsString($token);
        $deviceId = $created['data']['id'];
        self::assertSame($deviceId, $created['data']['deviceUuid']);
        self::assertSame('pending', $created['data']['status']);
        $listed = $this->json($client, 'GET', $host, '/api/v1/hr/terminals');
        self::assertStringNotContainsString($token, (string) json_encode($listed));

        [, $other] = $this->openTenant('other', $client);
        $keypair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($keypair));
        $secret = sodium_crypto_sign_secretkey($keypair);
        $this->call($client, 'POST', $other, '/api/v1/public/terminals/enroll', [
            'token' => $token,
            'publicKey' => $public,
            'macAddress' => 'AA:BB:CC:DD:EE:FF',
            'processId' => '424242',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('tenant_mismatch', $this->problem($client)['code']);

        $enrolled = $this->json($client, 'POST', $host, '/api/v1/public/terminals/enroll', [
            'token' => $token,
            'publicKey' => $public,
            'macAddress' => 'AA:BB:CC:DD:EE:FF',
            'hostname' => 'front-desk',
            'os' => 'kiosk',
            'appVersion' => '0.0.0',
            'processId' => '424242',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('active', $enrolled['data']['status']);
        self::assertSame($deviceId, $enrolled['data']['deviceId']);
        $this->call($client, 'POST', $host, '/api/v1/public/terminals/enroll', ['token' => $token, 'publicKey' => $public]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('enrollment_rejected', $this->problem($client)['code']);

        $this->call($client, 'POST', $host, '/api/v1/terminal/heartbeats', [
            'macAddress' => 'AA:BB:CC:DD:EE:FF',
            'processId' => '424242',
        ], ['HTTP_X_DEVICE_MAC' => 'AA:BB:CC:DD:EE:FF', 'HTTP_X_PROCESS_ID' => '424242']);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('unauthenticated', $this->problem($client)['code']);

        $heartbeat = $this->signed($client, 'POST', $host, '/api/v1/terminal/heartbeats', json_encode([
            'macAddress' => 'AA:BB:CC:DD:EE:FF',
            'processId' => '424242',
            'appVersion' => '0.0.0',
        ], JSON_THROW_ON_ERROR), $deviceId, $secret);
        self::assertResponseIsSuccessful();
        self::assertSame('active', $heartbeat['data']['status']);
        self::assertStringNotContainsString('424242', (string) json_encode($heartbeat));
        $store = self::getContainer()->get(TenantRegistryStore::class);
        self::assertInstanceOf(TenantRegistryStore::class, $store);
        $device = $store->findDevice($deviceId);
        self::assertNotNull($device);
        $switcher = self::getContainer()->get(TenantDatabaseSwitcher::class);
        self::assertInstanceOf(TenantDatabaseSwitcher::class, $switcher);
        $switcher->switchTo($device->tenantId());
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $row = $connection->fetchAssociative('SELECT payload FROM device_heartbeats WHERE device_id = ?', [$deviceId]);
        self::assertIsArray($row);
        self::assertIsString($row['payload']);
        self::assertStringContainsString('AA:BB:CC:DD:EE:FF', $row['payload']);
        self::assertStringNotContainsString('424242', $row['payload']);
        self::assertStringNotContainsString('processId', $row['payload']);

        $this->json($client, 'PUT', $host, '/api/v1/hr/device-policy', [
            'allowedNetworks' => [],
            'allowedIpRanges' => ['10.1.2.0/24'],
            'allowedAppVersions' => [],
        ]);
        $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts', json_encode(['code' => 'ZZZZ-ZZZZ-ZZ'], JSON_THROW_ON_ERROR), $deviceId, $secret);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('device_policy', $this->problem($client)['code']);
        $this->signed($client, 'POST', $host, '/api/v1/terminal/heartbeats', '{}', $deviceId, $secret);
        self::assertResponseIsSuccessful();

        $audit = $this->json($client, 'GET', $host, '/api/v1/hr/terminals/'.$deviceId.'/audit');
        $actions = array_column($audit['data'], 'action');
        self::assertContains('device.registered', $actions);
        self::assertContains('device.enrollment_completed', $actions);
        self::assertStringNotContainsString($token, (string) json_encode($audit));
        self::assertStringNotContainsString($public, (string) json_encode($audit));
    }

    public function testLifecycleAndCredentialRotationStayWithTheTenant(): void
    {
        [$client, $host] = $this->openTenant('lifecycle');
        $this->accounts()->tenantUser($this->slug($host), 'admin@example.com', 'Ada', self::PASSWORD, ['tenant_admin']);
        $this->login($client, $host, 'admin@example.com');
        $created = $this->json($client, 'POST', $host, '/api/v1/hr/terminals', [
            'displayName' => 'Gate',
            'requireActivation' => true,
        ]);
        $keypair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($keypair));
        $secret = sodium_crypto_sign_secretkey($keypair);
        $enrolled = $this->json($client, 'POST', $host, '/api/v1/public/terminals/enroll', [
            'token' => $created['data']['enrollmentToken'],
            'publicKey' => $public,
        ]);
        self::assertSame('registered', $enrolled['data']['status']);
        $deviceId = $created['data']['id'];
        $this->signed($client, 'POST', $host, '/api/v1/terminal/heartbeats', '{}', $deviceId, $secret);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('device_inactive', $this->problem($client)['code']);

        $activated = $this->json($client, 'POST', $host, '/api/v1/hr/terminals/'.$deviceId.'/activate');
        self::assertSame('active', $activated['data']['status']);
        self::assertNotNull($activated['data']['credentialRef']);
        $this->signed($client, 'POST', $host, '/api/v1/terminal/heartbeats', '{}', $deviceId, $secret);
        self::assertResponseIsSuccessful();

        $suspended = $this->json($client, 'POST', $host, '/api/v1/hr/terminals/'.$deviceId.'/suspend');
        self::assertSame('suspended', $suspended['data']['status']);
        self::assertNotNull($suspended['data']['suspendedAt']);
        $this->signed($client, 'POST', $host, '/api/v1/terminal/heartbeats', '{}', $deviceId, $secret);
        self::assertSame('device_inactive', $this->problem($client)['code']);
        $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts', '{"code":"AAAAAAAAAA"}', $deviceId, $secret);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('device_inactive', $this->problem($client)['code']);
        $this->json($client, 'POST', $host, '/api/v1/hr/terminals/'.$deviceId.'/activate');
        self::assertResponseIsSuccessful();

        $rotated = $this->json($client, 'POST', $host, '/api/v1/hr/terminals/'.$deviceId.'/credentials');
        $next = sodium_crypto_sign_keypair();
        $this->json($client, 'POST', $host, '/api/v1/public/terminals/enroll', [
            'token' => $rotated['data']['enrollmentToken'],
            'publicKey' => base64_encode(sodium_crypto_sign_publickey($next)),
        ]);
        self::assertResponseStatusCodeSame(201);
        $this->signed($client, 'POST', $host, '/api/v1/terminal/heartbeats', '{}', $deviceId, $secret);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('device_rejected', $this->problem($client)['code']);
        $this->signed($client, 'POST', $host, '/api/v1/terminal/heartbeats', '{}', $deviceId, sodium_crypto_sign_secretkey($next));
        self::assertResponseIsSuccessful();

        $revoked = $this->json($client, 'POST', $host, '/api/v1/hr/terminals/'.$deviceId.'/revoke');
        self::assertSame('revoked', $revoked['data']['status']);
        self::assertNotNull($revoked['data']['revokedAt']);
        $this->call($client, 'POST', $host, '/api/v1/hr/terminals/'.$deviceId.'/activate');
        self::assertResponseStatusCodeSame(409);
        $this->signed($client, 'POST', $host, '/api/v1/terminal/heartbeats', '{}', $deviceId, sodium_crypto_sign_secretkey($next));
        self::assertSame('device_inactive', $this->problem($client)['code']);
        $this->signed($client, 'POST', $host, '/api/v1/terminal/attempts', '{"code":"AAAAAAAAAA"}', $deviceId, sodium_crypto_sign_secretkey($next));
        self::assertResponseStatusCodeSame(403);
        self::assertSame('device_inactive', $this->problem($client)['code']);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $server
     */
    private function call(KernelBrowser $client, string $method, string $host, string $path, ?array $body = null, array $server = []): void
    {
        $server['HTTP_HOST'] = $host;
        $server['HTTP_ACCEPT'] = 'application/json';
        $content = null;
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($body, JSON_THROW_ON_ERROR);
        }
        $client->request($method, 'http://'.$host.$path, server: $server, content: $content);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $client, string $method, string $host, string $path, ?array $body = null): array
    {
        $this->call($client, $method, $host, $path, $body);
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    private function signed(KernelBrowser $client, string $method, string $host, string $path, string $body, string $deviceId, string $secret, array $server = []): array
    {
        $timestamp = (string) time();
        $nonce = str_replace('-', '', Uuid::v7()->toRfc4122());
        $payload = DeviceSignature::payload($timestamp, $nonce, $method, $path, DeviceSignature::bodyHash($body));
        $server['HTTP_HOST'] = $host;
        $server['HTTP_ACCEPT'] = 'application/json';
        $server['CONTENT_TYPE'] = 'application/json';
        $server['HTTP_X_DEVICE_ID'] = $deviceId;
        $server['HTTP_X_DEVICE_TIMESTAMP'] = $timestamp;
        $server['HTTP_X_DEVICE_NONCE'] = $nonce;
        $server['HTTP_X_DEVICE_SIGNATURE'] = base64_encode(sodium_crypto_sign_detached($payload, $secret));
        $client->request($method, 'http://'.$host.$path, server: $server, content: $body);
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
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

    /**
     * @return array{0: KernelBrowser, 1: string}
     */
    private function openTenant(string $prefix, ?KernelBrowser $client = null): array
    {
        $client ??= self::createClient();
        $host = $prefix.bin2hex(random_bytes(3)).'.localhost';
        $provision = self::getContainer()->get(ProvisionTenant::class);
        self::assertInstanceOf(ProvisionTenant::class, $provision);
        $provision->execute(new ProvisionTenantRequest($this->slug($host), 'Example AB', $host, 'Europe/Stockholm', 'en', 'cli'));

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
}
