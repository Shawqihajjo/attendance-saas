<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Identity\Application\OpenAccounts;
use App\Identity\Domain\AccountRecord;
use App\Media\MediaNotFoundException;
use App\Media\MediaStore;
use App\Platform\Device\DeviceSignature;
use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Tenant\Attendance\Infrastructure\PhotoStore;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class AttendancePhotoApiTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\xFF\xD9";

    public function testOnlyAnAuthorizedUserInTheSameTenantCanViewOrDeleteAPhotograph(): void
    {
        [$client, $host] = $this->openTenant('photos');
        $owner = $this->accounts()->tenantUser($this->slug($host), 'owner@example.com', 'Owner', self::PASSWORD, ['employee']);
        $other = $this->accounts()->tenantUser($this->slug($host), 'other@example.com', 'Other', self::PASSWORD, ['employee']);
        $this->accounts()->tenantUser($this->slug($host), 'hr-photos@example.com', 'Harper', self::PASSWORD, ['hr']);
        self::assertInstanceOf(AccountRecord::class, $owner);
        self::assertInstanceOf(AccountRecord::class, $other);
        self::assertNotNull($owner->employeeId);
        $tenantId = $this->tenantId($host);
        $photoId = $this->store($tenantId, $owner->employeeId);
        $path = '/api/v1/hr/attendance-photos/'.$photoId;

        $client->request('GET', 'http://'.$host.$path, server: ['HTTP_HOST' => $host]);
        self::assertResponseStatusCodeSame(401);

        $this->signed($client, 'GET', $host, $path);
        self::assertResponseStatusCodeSame(401);

        $this->login($client, $host, 'other@example.com');
        $this->call($client, 'GET', $host, $path);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'DELETE', $host, $path);
        self::assertResponseStatusCodeSame(403);

        $this->login($client, $host, 'owner@example.com');
        $this->call($client, 'GET', $host, $path);
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame(self::JPEG, $response->getContent());
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $cache = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('no-store', $cache);
        self::assertStringNotContainsString('public', $cache);
        self::assertSame('inline; filename="attendance-photo.jpg"', $response->headers->get('Content-Disposition'));
        $headers = (string) json_encode($response->headers->all());
        self::assertStringNotContainsString('var/photos', $headers);
        self::assertStringNotContainsString($tenantId, $headers);

        $this->login($client, $host, 'hr-photos@example.com');
        $this->call($client, 'GET', $host, $path);
        self::assertResponseIsSuccessful();
        $this->call($client, 'GET', $host, '/api/v1/hr/audit');
        self::assertResponseIsSuccessful();
        $audit = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('attendance.photo_viewed', $audit);
        self::assertStringNotContainsString('JFIF', $audit);
        self::assertStringNotContainsString('var/photos', $audit);
        self::assertStringNotContainsString($tenantId.'/', $audit);

        [, $otherHost] = $this->openTenant('photob', $client);
        $this->accounts()->tenantUser($this->slug($otherHost), 'admin-b@example.com', 'Blair', self::PASSWORD, ['tenant_admin']);
        $this->login($client, $otherHost, 'admin-b@example.com');
        $this->call($client, 'GET', $otherHost, $path);
        self::assertResponseStatusCodeSame(404);

        $this->login($client, $host, 'hr-photos@example.com');
        $this->call($client, 'DELETE', $host, $path);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('var/photos', (string) $client->getResponse()->getContent());
        $this->call($client, 'GET', $host, $path);
        self::assertResponseStatusCodeSame(404);
        $media = self::getContainer()->get(MediaStore::class);
        self::assertInstanceOf(MediaStore::class, $media);
        $this->expectException(MediaNotFoundException::class);
        $media->read($tenantId, $tenantId.'/'.$photoId);
    }

    public function testExpiredPhotographsAreErasedAndRetentionIsConfigurable(): void
    {
        [$client, $host] = $this->openTenant('retain');
        $owner = $this->accounts()->tenantUser($this->slug($host), 'kept@example.com', 'Kept', self::PASSWORD, ['employee']);
        $this->accounts()->tenantUser($this->slug($host), 'hr-retain@example.com', 'Harper', self::PASSWORD, ['hr']);
        self::assertInstanceOf(AccountRecord::class, $owner);
        self::assertNotNull($owner->employeeId);
        $tenantId = $this->tenantId($host);
        $photoId = $this->store($tenantId, $owner->employeeId);
        $this->tenantConnection($tenantId)->executeStatement(
            'UPDATE attendance_photos SET retained_until = ? WHERE id = ?',
            ['2020-01-01 00:00:00', $photoId],
        );

        $this->login($client, $host, 'kept@example.com');
        $this->call($client, 'GET', $host, '/api/v1/hr/attendance-photos/'.$photoId);
        self::assertResponseStatusCodeSame(404);
        $media = self::getContainer()->get(MediaStore::class);
        self::assertInstanceOf(MediaStore::class, $media);
        try {
            $media->read($tenantId, $tenantId.'/'.$photoId);
            $removed = null;
        } catch (MediaNotFoundException $exception) {
            $removed = $exception;
        }
        self::assertInstanceOf(MediaNotFoundException::class, $removed);

        $this->login($client, $host, 'kept@example.com');
        $this->call($client, 'PATCH', $host, '/api/v1/hr/settings/photo-retention', ['retentionDays' => 30]);
        self::assertResponseStatusCodeSame(403);

        $this->login($client, $host, 'hr-retain@example.com');
        $this->call($client, 'PATCH', $host, '/api/v1/hr/settings/photo-retention', ['retentionDays' => 0]);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'PATCH', $host, '/api/v1/hr/settings/photo-retention', ['retentionDays' => 30]);
        self::assertResponseIsSuccessful();
        $kept = $this->store($tenantId, $owner->employeeId, 30);
        $until = $this->tenantConnection($tenantId)->fetchOne('SELECT retained_until FROM attendance_photos WHERE id = ?', [$kept]);
        self::assertIsString($until);
        self::assertNotSame('', $until);

        $this->call($client, 'PATCH', $host, '/api/v1/hr/settings/photo-retention', ['retentionDays' => null]);
        self::assertResponseIsSuccessful();
        $open = $this->store($tenantId, $owner->employeeId, null);
        $cleared = $this->tenantConnection($tenantId)->fetchOne('SELECT retained_until FROM attendance_photos WHERE id = ?', [$open]);
        self::assertNull($cleared);
    }

    private function store(string $tenantId, string $employeeId, ?int $retentionDays = null): string
    {
        $switcher = self::getContainer()->get(TenantDatabaseSwitcher::class);
        $photos = self::getContainer()->get(PhotoStore::class);
        self::assertInstanceOf(TenantDatabaseSwitcher::class, $switcher);
        self::assertInstanceOf(PhotoStore::class, $photos);
        $switcher->switchTo($tenantId);
        try {
            return $photos->store($tenantId, $employeeId, 'image/jpeg', self::JPEG, new \DateTimeImmutable('2026-10-08 06:00:00 UTC'), $retentionDays);
        } finally {
            $switcher->reset();
        }
    }

    private function tenantConnection(string $tenantId): Connection
    {
        $switcher = self::getContainer()->get(TenantDatabaseSwitcher::class);
        self::assertInstanceOf(TenantDatabaseSwitcher::class, $switcher);
        $switcher->switchTo($tenantId);
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function tenantId(string $host): string
    {
        $registry = self::getContainer()->get(TenantRegistryStore::class);
        self::assertInstanceOf(TenantRegistryStore::class, $registry);
        $tenant = $registry->findTenantBySlug($this->slug($host));
        self::assertNotNull($tenant);

        return $tenant->id();
    }

    private function signed(KernelBrowser $client, string $method, string $host, string $path): void
    {
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $timestamp = (string) time();
        $nonce = str_replace('-', '', Uuid::v7()->toRfc4122());
        $payload = DeviceSignature::payload($timestamp, $nonce, $method, $path, DeviceSignature::bodyHash(''));
        $client->getCookieJar()->clear();
        $client->request($method, 'http://'.$host.$path, server: [
            'HTTP_HOST' => $host,
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DEVICE_ID' => Uuid::v7()->toRfc4122(),
            'HTTP_X_DEVICE_TIMESTAMP' => $timestamp,
            'HTTP_X_DEVICE_NONCE' => $nonce,
            'HTTP_X_DEVICE_SIGNATURE' => base64_encode(sodium_crypto_sign_detached($payload, $secret)),
        ]);
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
}
