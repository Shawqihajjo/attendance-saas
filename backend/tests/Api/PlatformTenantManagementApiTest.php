<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Identity\Application\OpenAccounts;
use App\Platform\TenantRegistry\Domain\TenantStatus;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantDatabaseConnection;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class PlatformTenantManagementApiTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    public function testPlatformAdminCanCreateSearchAndEditWithoutCrossingTenants(): void
    {
        $client = self::createClient();
        $slug = $this->suffix('north');
        $other = $this->suffix('south');
        $platform = $this->platformCookie($client, $slug);

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants', [
            'legalName' => 'Northwind AB',
            'slug' => $slug,
            'timezone' => 'Europe/Stockholm',
            'defaultLocale' => 'sv',
            'locales' => ['sv'],
            'administrator' => [
                'email' => 'admin@'.$slug.'.example',
                'displayName' => 'North Admin',
                'password' => self::PASSWORD,
            ],
        ], $platform);
        self::assertResponseStatusCodeSame(201);
        $created = $this->decode($client->getResponse()->getContent());
        $this->assertNoSecrets($client->getResponse()->getContent());
        self::assertSame($slug, $created['data']['slug']);
        self::assertSame('Northwind AB', $created['data']['legalName']);
        self::assertSame($slug.'.localhost', $created['data']['hostname']);
        self::assertSame('active', $created['data']['status']);
        self::assertSame('sv', $created['data']['defaultLocale']);
        self::assertSame(['sv'], $created['data']['enabledLocales']);
        self::assertSame('Europe/Stockholm', $created['data']['timezone']);
        self::assertSame('ready', $created['data']['provisioningStatus']);
        self::assertTrue($created['data']['administratorCreated']);
        self::assertArrayNotHasKey('database', $created['data']);
        self::assertArrayNotHasKey('password', $created['data']);
        $databaseName = $this->connection($slug)->databaseName();

        $this->login($client, $slug.'.localhost', '/api/v1/auth/login', 'admin@'.$slug.'.example', self::PASSWORD);
        self::assertResponseIsSuccessful();
        $tenantCookie = $this->cookieHeader($client);
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants', [
            'legalName' => 'Blocked AB',
            'slug' => $this->suffix('blocked'),
            'timezone' => 'Europe/Stockholm',
            'defaultLocale' => 'en',
        ], $tenantCookie);
        self::assertResponseStatusCodeSame(401);
        $this->call($client, 'GET', $slug.'.localhost', '/api/v1/hr/employees', null, $platform);
        self::assertResponseStatusCodeSame(401);
        $this->accounts()->tenantUser($slug, 'employee@'.$slug.'.example', 'Eli', self::PASSWORD, ['employee']);
        $this->login($client, $slug.'.localhost', '/api/v1/auth/login', 'employee@'.$slug.'.example', self::PASSWORD);
        self::assertResponseIsSuccessful();
        $employee = $this->cookieHeader($client);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/tenants', null, $employee);
        self::assertResponseStatusCodeSame(401);
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$slug.'/suspend', [], $employee);
        self::assertResponseStatusCodeSame(401);

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/tenants/'.$slug, null, $platform);
        self::assertResponseIsSuccessful();
        self::assertSame($slug, $this->decode($client->getResponse()->getContent())['data']['slug']);
        $this->assertNoSecrets($client->getResponse()->getContent());
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/tenants/'.$this->suffix('missing'), null, $platform);
        self::assertResponseStatusCodeSame(404);

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants', [
            'legalName' => 'Southwind AB',
            'slug' => $other,
            'hostname' => $other.'.localhost',
            'timezone' => 'Europe/Stockholm',
            'defaultLocale' => 'en',
        ], $platform);
        self::assertResponseStatusCodeSame(201);

        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug, [
            'legalName' => 'Northwind Updated AB',
            'timezone' => 'Europe/Oslo',
            'defaultLocale' => 'en',
            'locales' => ['en', 'sv'],
        ], $platform);
        self::assertResponseIsSuccessful();
        $updated = $this->decode($client->getResponse()->getContent());
        $this->assertNoSecrets($client->getResponse()->getContent());
        self::assertSame('Northwind Updated AB', $updated['data']['legalName']);
        self::assertSame('Europe/Oslo', $updated['data']['timezone']);
        self::assertSame('en', $updated['data']['defaultLocale']);
        self::assertSame($databaseName, $this->connection($slug)->databaseName());
        self::assertSame('present', $this->connection($slug)->credentialsState());
        self::assertSame('Europe/Stockholm', $this->tenant($other)['timezone']);

        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug, [
            'tenantId' => $this->tenant($other)['id'],
            'legalName' => 'Hijack AB',
        ], $platform);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Southwind AB', $this->tenant($other)['legalName']);

        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug, [
            'slug' => $other,
        ], $platform);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($slug, $this->tenant($slug)['slug']);

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/tenants?q='.$slug.'&status=active&sort=legalName&direction=asc', null, $platform);
        self::assertResponseIsSuccessful();
        $listed = $this->decode($client->getResponse()->getContent());
        $this->assertNoSecrets($client->getResponse()->getContent());
        self::assertCount(1, $listed['data']);
        self::assertSame($slug, $listed['data'][0]['slug']);
        self::assertSame(1, $listed['meta']['page']['total']);
        self::assertNotNull($listed['data'][0]['lastActivityAt']);

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/audit?tenant='.$slug, null, $platform);
        self::assertResponseIsSuccessful();
        $audit = $this->decode($client->getResponse()->getContent());
        $this->assertNoSecrets($client->getResponse()->getContent());
        $actions = array_column($audit['data'], 'action');
        self::assertContains('tenant.provisioned', $actions);
        self::assertContains('tenant.updated', $actions);
        self::assertNotContains('cli', array_column($audit['data'], 'actorId'));
    }

    public function testInvalidInputAndDuplicateSlugDoNotCreateAnActiveTenant(): void
    {
        $client = self::createClient();
        $slug = $this->suffix('valid');
        $platform = $this->platformCookie($client, $slug);
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants', [
            'legalName' => 'Valid AB',
            'slug' => $slug,
            'timezone' => 'Europe/Stockholm',
            'defaultLocale' => 'en',
        ], $platform);
        self::assertResponseStatusCodeSame(201);

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants', [
            'legalName' => 'Again AB',
            'slug' => $slug,
            'timezone' => 'Europe/Stockholm',
            'defaultLocale' => 'en',
        ], $platform);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('active', $this->tenant($slug)['status']);

        $duplicateHost = $this->suffix('host');
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants', [
            'legalName' => 'Host AB',
            'slug' => $duplicateHost,
            'hostname' => $slug.'.localhost',
            'timezone' => 'Europe/Stockholm',
            'defaultLocale' => 'en',
        ], $platform);
        self::assertResponseStatusCodeSame(409);
        self::assertNull($this->find($duplicateHost));
        self::assertSame($slug.'.localhost', $this->store()->findPrimaryDomain($this->requireTenant($slug)->id())?->hostname());

        $missing = $this->suffix('missing');
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants', [
            'legalName' => 'Missing AB',
            'slug' => $missing,
            'timezone' => 'Not/AZone',
            'defaultLocale' => 'en',
        ], $platform);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->find($missing));

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants', [
            'legalName' => 'Plan AB',
            'slug' => $this->suffix('plan'),
            'timezone' => 'Europe/Stockholm',
            'defaultLocale' => 'en',
            'plan' => 'enterprise',
        ], $platform);
        self::assertResponseStatusCodeSame(422);

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants', [
            'legalName' => 'Secret AB',
            'slug' => $this->suffix('secret'),
            'timezone' => 'Europe/Stockholm',
            'defaultLocale' => 'en',
            'password' => self::PASSWORD,
        ], $platform);
        self::assertResponseStatusCodeSame(422);
        $this->assertNoSecrets($client->getResponse()->getContent());

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/tenants?direction=sideways', null, $platform);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'. $this->suffix('absent').'/suspend', [], $platform);
        self::assertResponseStatusCodeSame(404);
    }

    public function testFailedProvisioningStaysIncompleteUntilARetrySucceeds(): void
    {
        $client = self::createClient();
        $failed = $this->suffix('stuck');
        $retry = $this->suffix('retry');
        $platform = $this->platformCookie($client, $failed);
        $this->createTenant($client, $platform, $failed);
        $this->createTenant($client, $platform, $retry);

        $stuck = $this->requireTenant($failed);
        $connection = $this->connection($failed);
        $connection->markDropped(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $status = new \ReflectionProperty($stuck, 'status');
        $status->setValue($stuck, TenantStatus::Provisioning);
        $this->store()->flush();

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$failed.'/activate', [], $platform);
        self::assertResponseStatusCodeSame(422);
        $this->assertNoSecrets($client->getResponse()->getContent());
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/tenants?q='.$failed, null, $platform);
        $stuckSummary = $this->row($client, $failed);
        self::assertSame('provisioning', $stuckSummary['status']);
        self::assertSame('removed', $stuckSummary['provisioningStatus']);

        $again = $this->requireTenant($retry);
        (new \ReflectionProperty($again, 'status'))->setValue($again, TenantStatus::Provisioning);
        $this->store()->flush();
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/tenants?q='.$retry, null, $platform);
        self::assertSame('incomplete', $this->row($client, $retry)['provisioningStatus']);
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$retry.'/activate', [], $platform);
        self::assertResponseIsSuccessful();
        $activated = $this->decode($client->getResponse()->getContent());
        $this->assertNoSecrets($client->getResponse()->getContent());
        self::assertSame('active', $activated['data']['status']);
        self::assertSame('ready', $activated['data']['provisioningStatus']);

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$retry.'/database/check', [], $platform);
        self::assertResponseIsSuccessful();
        $check = $this->decode($client->getResponse()->getContent());
        self::assertTrue($check['data']['reachable']);
        $this->assertNoSecrets($client->getResponse()->getContent());

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/audit?tenant='.$retry, null, $platform);
        $actions = array_column($this->decode($client->getResponse()->getContent())['data'], 'action');
        self::assertContains('tenant.activated', $actions);
        self::assertContains('tenant.migration_failed', array_column(
            $this->auditActions($client, $platform, $failed),
            'action',
        ));
    }

    public function testLifecycleKeepsDataUntilASeparateConfirmedDrop(): void
    {
        $client = self::createClient();
        $slug = $this->suffix('life');
        $neighbor = $this->suffix('neighbor');
        $platform = $this->platformCookie($client, $slug);
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants', [
            'legalName' => 'Lifecycle AB',
            'slug' => $slug,
            'timezone' => 'Europe/Stockholm',
            'defaultLocale' => 'en',
            'administrator' => [
                'email' => 'admin@'.$slug.'.example',
                'displayName' => 'Life Admin',
                'password' => self::PASSWORD,
            ],
        ], $platform);
        self::assertResponseStatusCodeSame(201);
        $this->createTenant($client, $platform, $neighbor);

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$slug.'/suspend', [], $platform);
        self::assertResponseIsSuccessful();
        self::assertSame('suspended', $this->tenant($slug)['status']);
        self::assertSame('present', $this->connection($slug)->credentialsState());
        $this->login($client, $slug.'.localhost', '/api/v1/auth/login', 'admin@'.$slug.'.example', self::PASSWORD);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('tenant_unavailable', $this->problem($client)['code']);
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$neighbor.'/database/check', [], $platform);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->decode($client->getResponse()->getContent())['data']['reachable']);

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$slug.'/activate', [], $platform);
        self::assertResponseStatusCodeSame(409);
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$slug.'/reactivate', [], $platform);
        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->tenant($slug)['status']);
        $this->login($client, $slug.'.localhost', '/api/v1/auth/login', 'admin@'.$slug.'.example', self::PASSWORD);
        self::assertResponseIsSuccessful();

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$slug.'/archive', [], $platform);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$slug.'/archive', [
            'confirmation' => $slug,
        ], $platform);
        self::assertResponseIsSuccessful();
        $archived = $this->decode($client->getResponse()->getContent());
        self::assertSame('decommissioned', $archived['data']['status']);
        self::assertSame('ready', $archived['data']['provisioningStatus']);
        self::assertSame('present', $this->connection($slug)->credentialsState());
        $this->login($client, $slug.'.localhost', '/api/v1/auth/login', 'admin@'.$slug.'.example', self::PASSWORD);
        self::assertResponseStatusCodeSame(403);

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$slug.'/drop-database', [
            'confirmation' => 'no',
        ], $platform);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('present', $this->connection($slug)->credentialsState());
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$neighbor.'/drop-database', [
            'confirmation' => $neighbor,
        ], $platform);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('present', $this->connection($neighbor)->credentialsState());

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$slug.'/drop-database', [
            'confirmation' => $slug,
        ], $platform);
        self::assertResponseIsSuccessful();
        $dropped = $this->decode($client->getResponse()->getContent());
        $this->assertNoSecrets($client->getResponse()->getContent());
        self::assertSame('removed', $dropped['data']['provisioningStatus']);
        self::assertSame('dropped', $this->connection($slug)->credentialsState());
        self::assertSame('', $this->connection($slug)->passwordCiphertext());
        self::assertSame('active', $this->tenant($neighbor)['status']);
    }

    private function platformCookie(KernelBrowser $client, string $slug): Cookie
    {
        $email = 'platform-'.$slug.'@example.com';
        $this->accounts()->platformAdmin($email, 'Platform Admin', self::PASSWORD);
        $this->login($client, 'localhost', '/api/v1/platform/login', $email, self::PASSWORD);
        self::assertResponseIsSuccessful();

        return $this->cookieHeader($client);
    }

    private function createTenant(KernelBrowser $client, Cookie $platform, string $slug): void
    {
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants', [
            'legalName' => 'Example AB',
            'slug' => $slug,
            'timezone' => 'Europe/Stockholm',
            'defaultLocale' => 'en',
        ], $platform);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return array<string, mixed>
     */
    private function tenant(string $slug): array
    {
        $found = $this->find($slug);
        self::assertIsArray($found);

        return $found;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $slug): ?array
    {
        $tenant = $this->store()->findTenantBySlug($slug);
        if ($tenant === null) {
            return null;
        }
        $settings = $this->store()->findSettings($tenant->id());

        return [
            'id' => $tenant->id(),
            'slug' => $tenant->slug(),
            'legalName' => $tenant->legalName(),
            'status' => $tenant->status()->value,
            'timezone' => $settings?->timezone(),
        ];
    }

    private function requireTenant(string $slug): \App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant
    {
        $tenant = $this->store()->findTenantBySlug($slug);
        self::assertNotNull($tenant);

        return $tenant;
    }

    private function connection(string $slug): TenantDatabaseConnection
    {
        $tenant = $this->store()->findTenantBySlug($slug);
        self::assertNotNull($tenant);
        $connection = $this->store()->findConnection($tenant->id());
        self::assertInstanceOf(TenantDatabaseConnection::class, $connection);

        return $connection;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditActions(KernelBrowser $client, Cookie $platform, string $slug): array
    {
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/audit?tenant='.$slug, null, $platform);
        self::assertResponseIsSuccessful();

        return $this->decode($client->getResponse()->getContent())['data'];
    }

    private function store(): TenantRegistryStore
    {
        $store = self::getContainer()->get(TenantRegistryStore::class);
        self::assertInstanceOf(TenantRegistryStore::class, $store);

        return $store;
    }

    private function accounts(): OpenAccounts
    {
        $accounts = self::getContainer()->get(OpenAccounts::class);
        self::assertInstanceOf(OpenAccounts::class, $accounts);

        return $accounts;
    }

    private function suffix(string $prefix): string
    {
        return $prefix.bin2hex(random_bytes(3));
    }

    private function assertNoSecrets(string|false $content): void
    {
        self::assertIsString($content);
        $lower = strtolower($content);
        self::assertStringNotContainsString('password', $lower);
        self::assertStringNotContainsString(self::PASSWORD, $content);
        self::assertStringNotContainsString('ciphertext', $lower);
    }

    private function login(KernelBrowser $client, string $host, string $path, string $email, string $password): void
    {
        $client->getCookieJar()->clear();
        $this->call($client, 'POST', $host, $path, ['email' => $email, 'password' => $password]);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(KernelBrowser $client, string $method, string $host, string $path, ?array $body = null, ?Cookie $cookie = null): void
    {
        $server = ['HTTP_HOST' => $host, 'HTTP_ACCEPT' => 'application/json'];
        $saved = [];
        if ($cookie !== null) {
            $saved = $client->getCookieJar()->all();
            $client->getCookieJar()->clear();
            $client->getCookieJar()->set(new Cookie($cookie->getName(), $cookie->getValue(), null, '/', $host));
        }
        $content = null;
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($body, JSON_THROW_ON_ERROR);
        }
        $client->request($method, 'http://'.$host.$path, server: $server, content: $content);
        if ($cookie !== null) {
            $client->getCookieJar()->clear();
            foreach ($saved as $savedCookie) {
                $client->getCookieJar()->set($savedCookie);
            }
        }
    }

    private function cookieHeader(KernelBrowser $client): Cookie
    {
        $cookies = $client->getCookieJar()->all();
        self::assertNotEmpty($cookies);

        return $cookies[array_key_last($cookies)];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(KernelBrowser $client, string $slug): array
    {
        foreach ($this->decode($client->getResponse()->getContent())['data'] as $row) {
            if ($row['slug'] === $slug) {
                return $row;
            }
        }
        self::fail('The tenant was not listed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function problem(KernelBrowser $client): array
    {
        return $this->decode($client->getResponse()->getContent());
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
