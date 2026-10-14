<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Identity\Application\OpenAccounts;
use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class TimezoneApiTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    public function testReportsReturnUtcInstantsAndDisplayInTheSelectedIanaZone(): void
    {
        [$client, $host] = $this->openTenant();
        $this->accounts()->tenantUser($this->slug($host), 'hr-time@example.com', 'Hanna', self::PASSWORD, ['hr']);
        $chicago = Uuid::v7()->toRfc4122();
        $this->storeEvent($host, $chicago, 'America/Chicago', '2026-10-07 06:42:15');

        $this->login($client, $host, 'hr-time@example.com');
        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=tenant&occurredAt=1999-01-01T00:00:00Z');
        self::assertResponseIsSuccessful();
        $tenant = $this->decode($client->getResponse()->getContent())['data'];
        self::assertSame('tenant', $tenant['display']);
        self::assertSame('Europe/Stockholm', $tenant['timezone']);
        self::assertSame('2026-10-07T06:42:15Z', $tenant['records'][0]['occurredAt']);
        self::assertSame('2026-10-07 08:42:15', $tenant['records'][0]['displayedAt']);

        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=report&timezone=America/New_York&date=2026-10-07');
        self::assertResponseIsSuccessful();
        $report = $this->decode($client->getResponse()->getContent())['data'];
        self::assertSame('report', $report['display']);
        self::assertSame('America/New_York', $report['timezone']);
        self::assertSame('2026-10-07T06:42:15Z', $report['records'][0]['occurredAt']);
        self::assertSame('2026-10-07 02:42:15', $report['records'][0]['displayedAt']);

        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=browser&browserTimezone=Asia/Tokyo');
        self::assertResponseIsSuccessful();
        $browser = $this->decode($client->getResponse()->getContent())['data'];
        self::assertSame('Asia/Tokyo', $browser['timezone']);
        self::assertSame('2026-10-07 15:42:15', $browser['records'][0]['displayedAt']);
        self::assertSame('2026-10-07T06:42:15Z', $browser['records'][0]['occurredAt']);

        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=terminal&deviceId='.$chicago);
        self::assertResponseIsSuccessful();
        $terminal = $this->decode($client->getResponse()->getContent())['data'];
        self::assertSame('America/Chicago', $terminal['timezone']);
        self::assertSame('2026-10-07 01:42:15', $terminal['records'][0]['displayedAt']);
        self::assertSame('2026-10-07T06:42:15Z', $terminal['records'][0]['occurredAt']);

        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=report&timezone=UTC+1');
        self::assertResponseStatusCodeSame(422);

        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=tenant&date=2026-01-16');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decode($client->getResponse()->getContent())['data']['records']);
    }

    private function storeEvent(string $host, string $deviceId, string $timezone, string $occurredAt): void
    {
        $tenant = $this->tenant($host);
        $switcher = self::getContainer()->get(TenantDatabaseSwitcher::class);
        self::assertInstanceOf(TenantDatabaseSwitcher::class, $switcher);
        $switcher->switchTo($tenant->id());
        try {
            $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
            self::assertInstanceOf(Connection::class, $connection);
            $employeeId = Uuid::v7()->toRfc4122();
            $connection->insert('employees', [
                'id' => $employeeId,
                'employee_number' => strtoupper(str_replace('-', '', $employeeId)),
                'display_name' => 'Ada',
                'status' => 'active',
                'created_at' => '2026-10-07 06:00:00',
                'updated_at' => '2026-10-07 06:00:00',
            ]);
            $connection->insert('devices', [
                'id' => $deviceId,
                'platform_device_id' => Uuid::v7()->toRfc4122(),
                'display_name' => 'Desk',
                'timezone' => $timezone,
                'created_at' => '2026-10-07 06:00:00',
            ]);
            $connection->insert('attendance_events', [
                'id' => Uuid::v7()->toRfc4122(),
                'session_id' => Uuid::v7()->toRfc4122(),
                'employee_id' => $employeeId,
                'event_type' => 'clock_in',
                'occurred_at' => $occurredAt,
                'device_id' => $deviceId,
            ]);
        } finally {
            $switcher->reset();
        }
    }

    /**
     * @return array{0: KernelBrowser, 1: string}
     */
    private function openTenant(): array
    {
        $client = self::createClient();
        $suffix = bin2hex(random_bytes(3));
        $host = 'zone'.$suffix.'.localhost';
        $provision = self::getContainer()->get(ProvisionTenant::class);
        self::assertInstanceOf(ProvisionTenant::class, $provision);
        $provision->execute(new ProvisionTenantRequest('zone'.$suffix, 'Example AB', $host, 'Europe/Stockholm', 'en', 'cli'));

        return [$client, $host];
    }

    private function tenant(string $host): Tenant
    {
        $registry = self::getContainer()->get(TenantRegistryStore::class);
        self::assertInstanceOf(TenantRegistryStore::class, $registry);
        $found = $registry->findTenantBySlug($this->slug($host));
        self::assertInstanceOf(Tenant::class, $found);

        return $found;
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
    private function decode(string|false $content): array
    {
        self::assertIsString($content);
        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
