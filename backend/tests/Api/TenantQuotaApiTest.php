<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Identity\Application\OpenAccounts;
use App\Platform\Device\DeviceAdministration;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class TenantQuotaApiTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    public function testPlatformAdminConfiguresLimitsAndTenantAdminCanOnlyReadUsage(): void
    {
        $client = self::createClient();
        $slug = 'quota'.bin2hex(random_bytes(3));
        $platform = $this->platformCookie($client, $slug);
        $this->createTenant($client, $platform, $slug, true);
        $tenant = $this->decode($client->getResponse()->getContent())['data'];
        self::assertArrayHasKey('id', $tenant);

        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug.'/quotas', [
            'maxUsers' => 2,
            'maxEmployees' => null,
            'maxActiveDevices' => 1,
        ], $platform);
        self::assertResponseIsSuccessful();
        $saved = $this->decode($client->getResponse()->getContent())['data'];
        self::assertSame(2, $saved['maxUsers']);
        self::assertNull($saved['maxEmployees']);
        self::assertSame(1, $saved['maxActiveDevices']);
        self::assertSame(1, $saved['usage']['users']['used']);
        self::assertSame('ok', $saved['usage']['employees']['level']);
        self::assertFalse($saved['usage']['users']['overLimit']);

        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug.'/quotas', [
            'maxUsers' => -1,
            'maxEmployees' => null,
            'maxActiveDevices' => 1,
        ], $platform);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug.'/quotas', [
            'maxUsers' => 2,
            'maxEmployees' => null,
            'tenantId' => $tenant['id'],
        ], $platform);
        self::assertResponseStatusCodeSame(422);

        $this->login($client, $slug.'.localhost', '/api/v1/auth/login', 'admin@'.$slug.'.example', self::PASSWORD);
        $admin = $this->cookieHeader($client);
        $this->call($client, 'GET', $slug.'.localhost', '/api/v1/tenant/usage', null, $admin);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->decode($client->getResponse()->getContent())['data']['maxUsers']);
        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug.'/quotas', [
            'maxUsers' => 9,
            'maxEmployees' => 9,
            'maxActiveDevices' => 9,
        ], $admin);
        self::assertResponseStatusCodeSame(401);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/tenants/'.$slug.'/usage', null, $platform);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->decode($client->getResponse()->getContent())['data']['maxUsers']);

        $this->accounts()->tenantUser($slug, 'employee@'.$slug.'.example', 'Eli', self::PASSWORD, ['employee']);
        $this->login($client, $slug.'.localhost', '/api/v1/auth/login', 'employee@'.$slug.'.example', self::PASSWORD);
        $employee = $this->cookieHeader($client);
        $this->call($client, 'GET', $slug.'.localhost', '/api/v1/tenant/usage', null, $employee);
        self::assertResponseStatusCodeSame(403);

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/audit?tenant='.$slug, null, $platform);
        self::assertResponseIsSuccessful();
        $audit = $this->decode($client->getResponse()->getContent())['data'];
        $change = null;
        foreach ($audit as $entry) {
            if ($entry['action'] === 'tenant.quotas_updated') {
                $change = $entry;
            }
        }
        self::assertIsArray($change);
        self::assertSame('success', $change['result']);
        self::assertSame(2, $change['metadata']['maxUsers']);
        self::assertNull($change['metadata']['previousMaxUsers']);
        self::assertArrayNotHasKey('password', $change['metadata']);
    }

    public function testTenantAdministratorCanReadOnlyTheSignedInTenant(): void
    {
        $client = self::createClient();
        $slug = 'view'.bin2hex(random_bytes(3));
        $neighbor = 'else'.bin2hex(random_bytes(3));
        $platform = $this->platformCookie($client, $slug);
        $this->createTenant($client, $platform, $slug, true);
        $this->createTenant($client, $platform, $neighbor, true);
        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug.'/quotas', [
            'maxUsers' => 5,
            'maxEmployees' => 5,
            'maxActiveDevices' => 1,
        ], $platform);
        self::assertResponseIsSuccessful();

        $this->login($client, $slug.'.localhost', '/api/v1/auth/login', 'admin@'.$slug.'.example', self::PASSWORD);
        $admin = $this->cookieHeader($client);
        $this->call($client, 'GET', $slug.'.localhost', '/api/v1/tenant/usage', null, $admin);
        self::assertResponseIsSuccessful();
        $own = $this->decode($client->getResponse()->getContent())['data'];
        self::assertSame(5, $own['maxUsers']);
        self::assertSame(1, $own['usage']['users']['used']);

        $this->call($client, 'PATCH', $slug.'.localhost', '/api/v1/tenant/usage', [
            'maxUsers' => 9,
            'maxEmployees' => 9,
            'maxActiveDevices' => 9,
        ], $admin);
        self::assertResponseStatusCodeSame(405);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/tenants/'.$slug.'/quotas', null, $admin);
        self::assertResponseStatusCodeSame(401);
        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$neighbor.'/quotas', [
            'maxUsers' => 1,
            'maxEmployees' => 1,
            'maxActiveDevices' => 1,
        ], $admin);
        self::assertResponseStatusCodeSame(401);
        $this->call($client, 'GET', $neighbor.'.localhost', '/api/v1/tenant/usage', null, $admin);
        self::assertResponseStatusCodeSame(403);
        $denied = $this->decode($client->getResponse()->getContent());
        self::assertSame('tenant_mismatch', $denied['code']);
        self::assertArrayNotHasKey('maxUsers', $denied);

        $this->accounts()->tenantUser($slug, 'hr@'.$slug.'.example', 'Hedy', self::PASSWORD, ['hr']);
        $this->login($client, $slug.'.localhost', '/api/v1/auth/login', 'hr@'.$slug.'.example', self::PASSWORD);
        $hr = $this->cookieHeader($client);
        $this->call($client, 'GET', $slug.'.localhost', '/api/v1/tenant/usage', null, $hr);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/hr/employees/import', [
            'employeeNumber' => 'E900',
            'name' => 'Imported Person',
        ], $hr);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUserEmployeeAndDeviceLimitsBlockIncreasesAndKeepExistingRows(): void
    {
        $client = self::createClient();
        $slug = 'limit'.bin2hex(random_bytes(3));
        $neighbor = 'other'.bin2hex(random_bytes(3));
        $platform = $this->platformCookie($client, $slug);
        $this->createTenant($client, $platform, $slug, true);
        $this->createTenant($client, $platform, $neighbor, false);
        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug.'/quotas', [
            'maxUsers' => 1,
            'maxEmployees' => 1,
            'maxActiveDevices' => 1,
        ], $platform);
        self::assertResponseIsSuccessful();

        $this->login($client, $slug.'.localhost', '/api/v1/auth/login', 'admin@'.$slug.'.example', self::PASSWORD);
        $admin = $this->cookieHeader($client);
        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/hr/users', [
            'email' => 'second@'.$slug.'.example',
            'displayName' => 'Second User',
            'password' => self::PASSWORD,
            'roles' => ['employee'],
        ], $admin);
        self::assertResponseStatusCodeSame(409);
        $problem = $this->decode($client->getResponse()->getContent());
        self::assertSame('tenant_quota_exceeded', $problem['code']);
        self::assertSame('users', $problem['resource']);
        self::assertSame(1, $problem['current']);
        self::assertSame(1, $problem['limit']);
        self::assertSame('The user limit has been reached.', $problem['detail']);

        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/hr/employees', [
            'employeeNumber' => 'E100',
            'name' => 'Extra Person',
        ], $admin);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('employees', $this->decode($client->getResponse()->getContent())['resource']);
        $this->call($client, 'GET', $slug.'.localhost', '/api/v1/hr/employees', null, $admin);
        self::assertCount(1, $this->decode($client->getResponse()->getContent())['data']);

        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug.'/quotas', [
            'maxUsers' => null,
            'maxEmployees' => null,
            'maxActiveDevices' => 1,
        ], $platform);
        self::assertResponseIsSuccessful();
        self::assertSame('ok', $this->decode($client->getResponse()->getContent())['data']['usage']['employees']['level']);
        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/hr/employees', [
            'employeeNumber' => 'E100',
            'name' => 'Extra Person',
        ], $admin);
        self::assertResponseStatusCodeSame(201);
        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/hr/employees/import', [
            'employeeNumber' => 'E101',
            'name' => 'Imported Person',
        ], $admin);
        self::assertResponseStatusCodeSame(404);

        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug.'/quotas', [
            'maxUsers' => null,
            'maxEmployees' => 1,
            'maxActiveDevices' => 1,
        ], $platform);
        self::assertResponseIsSuccessful();
        $lowered = $this->decode($client->getResponse()->getContent())['data'];
        self::assertSame('over', $lowered['usage']['employees']['level']);
        self::assertTrue($lowered['usage']['employees']['overLimit']);
        $this->call($client, 'GET', $slug.'.localhost', '/api/v1/hr/employees', null, $admin);
        self::assertCount(2, $this->decode($client->getResponse()->getContent())['data']);
        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/hr/employees', [
            'employeeNumber' => 'E102',
            'name' => 'Blocked Person',
        ], $admin);
        self::assertResponseStatusCodeSame(409);
        $this->call($client, 'GET', $slug.'.localhost', '/api/v1/hr/employees', null, $admin);
        $kept = $this->decode($client->getResponse()->getContent())['data'];
        self::assertIsArray($kept);
        $employeeId = $kept[0]['id'] ?? null;
        self::assertIsString($employeeId);
        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/hr/employees/'.$employeeId.'/status', [
            'status' => 'inactive',
        ], $admin);
        self::assertResponseIsSuccessful();
        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/hr/employees/'.$employeeId.'/status', [
            'status' => 'active',
        ], $admin);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('employees', $this->decode($client->getResponse()->getContent())['resource']);
        $this->call($client, 'GET', $slug.'.localhost', '/api/v1/hr/employees/'.$employeeId, null, $admin);
        self::assertSame('inactive', $this->decode($client->getResponse()->getContent())['data']['status']);

        $this->login($client, $neighbor.'.localhost', '/api/v1/auth/login', 'admin@'.$neighbor.'.example', self::PASSWORD);
        self::assertResponseStatusCodeSame(401);
        $neighborAdmin = $this->accounts()->tenantUser($neighbor, 'admin@'.$neighbor.'.example', 'Neighbor Admin', self::PASSWORD, ['tenant_admin']);
        self::assertNotSame('', $neighborAdmin->id);
        $this->login($client, $neighbor.'.localhost', '/api/v1/auth/login', 'admin@'.$neighbor.'.example', self::PASSWORD);
        $neighborCookie = $this->cookieHeader($client);
        $this->call($client, 'POST', $neighbor.'.localhost', '/api/v1/hr/employees', [
            'employeeNumber' => 'N100',
            'name' => 'Neighbor Person',
        ], $neighborCookie);
        self::assertResponseStatusCodeSame(201);

        $registered = $this->enrollTerminal($client, $slug, $admin, 'Front desk', true);
        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/hr/terminals/'.$registered.'/activate', [], $admin);
        self::assertResponseIsSuccessful();
        $second = $this->enrollTerminal($client, $slug, $admin, 'Side desk', true);
        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/hr/terminals/'.$second.'/activate', [], $admin);
        $activation = $this->decode($client->getResponse()->getContent());
        self::assertSame(409, $client->getResponse()->getStatusCode(), json_encode($activation) ?: '');
        self::assertSame('devices', $activation['resource'] ?? null, json_encode($activation) ?: '');
        self::assertSame('registered', $this->deviceStatus($slug, $second));
        $automatic = $this->enrollTerminal($client, $slug, $admin, 'Back door', false);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame('devices', $this->decode($client->getResponse()->getContent())['resource']);
        self::assertSame('pending', $this->deviceStatus($slug, $automatic));

        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug.'/quotas', [
            'maxUsers' => null,
            'maxEmployees' => 1,
            'maxActiveDevices' => 0,
        ], $platform);
        self::assertResponseIsSuccessful();
        $reducedDevices = $this->decode($client->getResponse()->getContent())['data'];
        self::assertSame('over', $reducedDevices['usage']['devices']['level']);
        self::assertTrue($reducedDevices['usage']['devices']['overLimit']);
        self::assertSame('active', $this->deviceStatus($slug, $registered));

        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/hr/terminals/'.$registered.'/suspend', [], $admin);
        self::assertResponseIsSuccessful();
        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug.'/quotas', [
            'maxUsers' => null,
            'maxEmployees' => 1,
            'maxActiveDevices' => 0,
        ], $platform);
        self::assertResponseIsSuccessful();
        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/hr/terminals/'.$registered.'/activate', [], $admin);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('suspended', $this->deviceStatus($slug, $registered));
        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug.'/quotas', [
            'maxUsers' => null,
            'maxEmployees' => 1,
            'maxActiveDevices' => 1,
        ], $platform);
        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/hr/terminals/'.$registered.'/activate', [], $admin);
        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->deviceStatus($slug, $registered));
    }

    public function testOverlappingEmployeeCreatesCannotShareTheLastSlot(): void
    {
        $client = self::createClient();
        $slug = 'race'.bin2hex(random_bytes(3));
        $platform = $this->platformCookie($client, $slug);
        $this->createTenant($client, $platform, $slug, false);
        $tenantId = $this->decode($client->getResponse()->getContent())['data']['id'];
        $this->call($client, 'PATCH', 'localhost', '/api/v1/platform/tenants/'.$slug.'/quotas', [
            'maxUsers' => null,
            'maxEmployees' => 1,
            'maxActiveDevices' => null,
        ], $platform);
        self::assertResponseIsSuccessful();

        $first = $this->startRacer($tenantId, 'RACE01');
        $second = $this->startRacer($tenantId, 'RACE02');
        $results = [
            $this->finishRacer($first),
            $this->finishRacer($second),
        ];
        sort($results);
        self::assertSame(['CREATED', 'QUOTA'], $results);

        $store = self::getContainer()->get(TenantRegistryStore::class);
        self::assertInstanceOf(TenantRegistryStore::class, $store);
        $switcher = self::getContainer()->get(\App\TenantContext\Domain\TenantDatabaseSwitcher::class);
        self::assertInstanceOf(\App\TenantContext\Domain\TenantDatabaseSwitcher::class, $switcher);
        $switcher->switchTo($tenantId);
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM employees WHERE status = 'active'"));
        $switcher->reset();
    }

    private function enrollTerminal(KernelBrowser $client, string $slug, Cookie $admin, string $name, bool $activationRequired): string
    {
        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/hr/terminals', [
            'displayName' => $name,
            'requireActivation' => $activationRequired,
        ], $admin);
        self::assertResponseStatusCodeSame(201);
        $created = $this->decode($client->getResponse()->getContent())['data'];
        self::assertIsArray($created);
        $deviceId = $created['id'];
        $token = $created['enrollmentToken'];
        self::assertIsString($deviceId);
        self::assertIsString($token);
        $public = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        $this->call($client, 'POST', $slug.'.localhost', '/api/v1/public/terminals/enroll', [
            'token' => $token,
            'publicKey' => $public,
        ]);
        if ($activationRequired) {
            self::assertResponseStatusCodeSame(201);
        }

        return $deviceId;
    }

    private function deviceStatus(string $slug, string $deviceId): string
    {
        $devices = self::getContainer()->get(DeviceAdministration::class);
        self::assertInstanceOf(DeviceAdministration::class, $devices);
        $tenant = self::getContainer()->get(TenantRegistryStore::class);
        self::assertInstanceOf(TenantRegistryStore::class, $tenant);
        $found = $tenant->findTenantBySlug($slug);
        self::assertNotNull($found);
        foreach ($devices->list($found->id()) as $device) {
            if ($device['id'] === $deviceId) {
                return (string) $device['status'];
            }
        }
        self::fail('The device was not listed.');
    }

    /**
     * @return array{process: resource, out: resource, err: resource}
     */
    private function startRacer(string $tenantId, string $number): array
    {
        $pipes = [];
        $process = proc_open(
            ['php', 'tests/Support/quota_racer.php', $tenantId, $number],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        return ['process' => $process, 'out' => $pipes[1], 'err' => $pipes[2]];
    }

    /**
     * @param array{process: resource, out: resource, err: resource} $racer
     */
    private function finishRacer(array $racer): string
    {
        $output = stream_get_contents($racer['out']);
        $error = stream_get_contents($racer['err']);
        fclose($racer['out']);
        fclose($racer['err']);
        $exit = proc_close($racer['process']);
        self::assertSame(0, $exit, is_string($error) ? $error : '');

        return trim(is_string($output) ? $output : '');
    }

    private function platformCookie(KernelBrowser $client, string $slug): Cookie
    {
        $email = 'platform-'.$slug.'@example.com';
        $this->accounts()->platformAdmin($email, 'Platform Admin', self::PASSWORD);
        $this->login($client, 'localhost', '/api/v1/platform/login', $email, self::PASSWORD);
        self::assertResponseIsSuccessful();

        return $this->cookieHeader($client);
    }

    private function createTenant(KernelBrowser $client, Cookie $platform, string $slug, bool $administrator): void
    {
        $body = [
            'legalName' => 'Quota AB',
            'slug' => $slug,
            'timezone' => 'Europe/Stockholm',
            'defaultLocale' => 'en',
        ];
        if ($administrator) {
            $body['administrator'] = [
                'email' => 'admin@'.$slug.'.example',
                'displayName' => 'Quota Admin',
                'password' => self::PASSWORD,
            ];
        }
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants', $body, $platform);
        self::assertResponseStatusCodeSame(201);
    }

    private function accounts(): OpenAccounts
    {
        $accounts = self::getContainer()->get(OpenAccounts::class);
        self::assertInstanceOf(OpenAccounts::class, $accounts);

        return $accounts;
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
    private function decode(string|false $content): array
    {
        self::assertIsString($content);
        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
