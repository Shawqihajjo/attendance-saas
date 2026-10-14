<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Identity\Application\OpenAccounts;
use App\Identity\Domain\AccountRecord;
use App\Platform\TenantRegistry\Application\DeviceEnrollment;
use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Console\Tester\CommandTester;

final class AuthorizationApiTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    public function testFailedSignInUsesOneResponseAndAuditsWithoutThePassword(): void
    {
        [$client, $host] = $this->openTenant('fail');
        $this->accounts()->tenantUser($this->slug($host), 'ada@example.com', 'Ada', self::PASSWORD, ['employee']);

        $this->login($client, $host, '/api/v1/auth/login', 'ada@example.com', 'wrong-password-value');
        self::assertResponseStatusCodeSame(401);
        $wrong = $this->problem($client);

        $this->login($client, $host, '/api/v1/auth/login', 'missing@example.com', self::PASSWORD);
        self::assertResponseStatusCodeSame(401);
        self::assertSame($wrong, $this->problem($client));
        self::assertSame('invalid_credentials', $wrong['code']);
        self::assertSame('The email or password is incorrect.', $wrong['detail']);

        $this->login($client, $host, '/api/v1/auth/login', 'ada@example.com', self::PASSWORD);
        self::assertResponseIsSuccessful();
        $cookie = $this->cookieHeader($client);
        $session = $this->decode($client->getResponse()->getContent());
        self::assertTrue($session['data']['authenticated']);
        self::assertSame('tenant', $session['data']['kind']);
        self::assertArrayNotHasKey('tenantId', $session['data']);
        self::assertNotContains('users.manage', $session['data']['permissions']);

        $this->call($client, 'POST', $host, '/api/v1/auth/logout');
        self::assertResponseIsSuccessful();
        $this->call($client, 'GET', $host, '/api/v1/me/profile', null, $cookie);
        self::assertResponseStatusCodeSame(401);

        $this->login($client, 'localhost', '/api/v1/auth/login', 'ada@example.com', self::PASSWORD);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('tenant_not_found', $this->problem($client)['code']);

        $this->login($client, $host, '/api/v1/auth/login', 'ada@example.com', self::PASSWORD, ['database' => 'platform']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('tenant_mismatch', $this->problem($client)['code']);

        $this->login($client, $host, '/api/v1/platform/login', 'ada@example.com', self::PASSWORD);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $this->problem($client)['code']);

        $admin = $this->accounts()->tenantUser($this->slug($host), 'hr-audit@example.com', 'Harper', self::PASSWORD, ['hr']);
        self::assertInstanceOf(AccountRecord::class, $admin);
        $this->login($client, $host, '/api/v1/auth/login', 'hr-audit@example.com', self::PASSWORD);
        $this->call($client, 'GET', $host, '/api/v1/hr/audit');
        self::assertResponseIsSuccessful();
        $audit = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString(self::PASSWORD, $audit);
        self::assertStringNotContainsString('wrong-password-value', $audit);
        self::assertStringNotContainsString('passwordHash', $audit);
        self::assertStringContainsString('auth.login_failed', $audit);
        self::assertStringContainsString('auth.login_succeeded', $audit);
    }

    public function testRolesAndAssignmentsAreEnforcedOnTheServer(): void
    {
        [$client, $host] = $this->openTenant('roles');
        $slug = $this->slug($host);
        $admin = $this->accounts()->tenantUser($slug, 'admin@example.com', 'Ada Admin', self::PASSWORD, ['tenant_admin']);
        $hr = $this->accounts()->tenantUser($slug, 'hr@example.com', 'Harper HR', self::PASSWORD, ['hr']);
        $manager = $this->accounts()->tenantUser($slug, 'manager@example.com', 'Mia Manager', self::PASSWORD, ['manager']);
        $employee = $this->accounts()->tenantUser($slug, 'employee@example.com', 'Eli Employee', self::PASSWORD, ['employee']);
        $other = $this->accounts()->tenantUser($slug, 'other@example.com', 'Otto Other', self::PASSWORD, ['employee']);

        $this->login($client, $host, '/api/v1/auth/login', 'employee@example.com', self::PASSWORD);
        $employeeCookie = $this->cookieHeader($client);
        $this->call($client, 'GET', $host, '/api/v1/me/profile');
        self::assertResponseIsSuccessful();
        $profile = $this->decode($client->getResponse()->getContent());
        self::assertSame('Eli Employee', $profile['data']['displayName']);
        self::assertArrayNotHasKey('tenantId', $profile['data']);
        $this->call($client, 'GET', $host, '/api/v1/me/attendance');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decode($client->getResponse()->getContent())['data']['records']);
        $this->call($client, 'POST', $host, '/api/v1/me/attendance-corrections', [
            'localDate' => '2020-01-06',
            'localTime' => '08:00',
            'action' => 'clock_in',
            'reason' => 'Missed clock-in',
        ]);
        self::assertResponseStatusCodeSame(201);
        $ownCorrection = $this->decode($client->getResponse()->getContent())['data']['id'];
        $this->call($client, 'GET', $host, '/api/v1/hr/employees');
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'GET', $host, '/api/v1/hr/users');
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'GET', $host, '/api/v1/hr/audit');
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'GET', $host, '/api/v1/hr/reports');
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'GET', $host, '/api/v1/hr/attendance');
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'POST', $host, '/api/v1/hr/attendance-corrections/'.$ownCorrection.'/decision', ['decision' => 'approved']);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'PATCH', $host, '/api/v1/hr/settings/timezone', ['timezone' => 'Europe/Stockholm']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('forbidden', $this->problem($client)['code']);

        $this->login($client, $host, '/api/v1/auth/login', 'other@example.com', self::PASSWORD);
        $this->call($client, 'POST', $host, '/api/v1/me/attendance-corrections', [
            'localDate' => '2020-01-06',
            'localTime' => '08:00',
            'action' => 'clock_in',
            'reason' => 'Other request',
        ]);
        self::assertResponseStatusCodeSame(201);
        $otherCorrection = $this->decode($client->getResponse()->getContent())['data']['id'];

        $this->login($client, $host, '/api/v1/auth/login', 'admin@example.com', self::PASSWORD);
        $this->call($client, 'POST', $host, '/api/v1/hr/manager-assignments', [
            'managerEmployeeId' => $manager->employeeId,
            'employeeId' => $employee->employeeId,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->login($client, $host, '/api/v1/auth/login', 'manager@example.com', self::PASSWORD);
        $managerCookie = $this->cookieHeader($client);
        $this->call($client, 'GET', $host, '/api/v1/hr/employees');
        self::assertResponseIsSuccessful();
        $visible = array_column($this->decode($client->getResponse()->getContent())['data'], 'id');
        self::assertSame([$employee->employeeId], $visible);
        $this->call($client, 'GET', $host, '/api/v1/hr/employees/'.$employee->employeeId);
        self::assertResponseIsSuccessful();
        $this->call($client, 'GET', $host, '/api/v1/hr/employees/'.$other->employeeId);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'GET', $host, '/api/v1/hr/employees/01999999-9999-7999-8999-999999999999');
        self::assertResponseStatusCodeSame(404);
        $this->call($client, 'POST', $host, '/api/v1/hr/attendance-corrections/'.$ownCorrection.'/decision', ['decision' => 'approved']);
        self::assertResponseIsSuccessful();
        $this->call($client, 'POST', $host, '/api/v1/hr/attendance-corrections/'.$otherCorrection.'/decision', ['decision' => 'rejected']);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'GET', $host, '/api/v1/hr/reports');
        self::assertSame('team', $this->decode($client->getResponse()->getContent())['data']['scope']);
        $this->call($client, 'POST', $host, '/api/v1/hr/departments', ['name' => 'People']);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'POST', $host, '/api/v1/hr/schedules', ['employeeId' => $employee->employeeId, 'name' => 'Weekdays']);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'GET', $host, '/api/v1/hr/users');
        self::assertResponseStatusCodeSame(403);

        $this->login($client, $host, '/api/v1/auth/login', 'hr@example.com', self::PASSWORD);
        $this->call($client, 'GET', $host, '/api/v1/hr/employees');
        self::assertGreaterThan(1, count($this->decode($client->getResponse()->getContent())['data']));
        $this->call($client, 'POST', $host, '/api/v1/hr/attendance-corrections/'.$otherCorrection.'/decision', ['decision' => 'rejected']);
        self::assertResponseIsSuccessful();
        $this->call($client, 'POST', $host, '/api/v1/hr/attendance-corrections/'.$otherCorrection.'/decision', ['decision' => 'approved']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('conflict', $this->problem($client)['code']);
        $this->call($client, 'GET', $host, '/api/v1/hr/reports');
        self::assertSame('tenant', $this->decode($client->getResponse()->getContent())['data']['scope']);
        $this->call($client, 'PATCH', $host, '/api/v1/hr/settings/photo-policy', ['photoRequired' => false]);
        self::assertResponseIsSuccessful();
        $this->call($client, 'PATCH', $host, '/api/v1/hr/settings/timezone', ['timezone' => 'Europe/Stockholm']);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'PUT', $host, '/api/v1/hr/settings/locales', ['locales' => ['en', 'sv'], 'defaultLocale' => 'sv']);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'PATCH', $host, '/api/v1/hr/branding', ['displayName' => 'Hidden']);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'POST', $host, '/api/v1/hr/departments', ['name' => 'People']);
        self::assertResponseStatusCodeSame(201);
        $this->call($client, 'POST', $host, '/api/v1/hr/schedules', ['employeeId' => $employee->employeeId, 'name' => 'Weekdays']);
        self::assertResponseStatusCodeSame(201);
        $this->call($client, 'GET', $host, '/api/v1/hr/users');
        self::assertResponseStatusCodeSame(403);

        $this->login($client, $host, '/api/v1/auth/login', 'admin@example.com', self::PASSWORD);
        $this->call($client, 'PATCH', $host, '/api/v1/hr/settings/timezone', ['timezone' => 'UTC+1']);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'PATCH', $host, '/api/v1/hr/settings/timezone', ['timezone' => 'Europe/Stockholm']);
        self::assertResponseIsSuccessful();
        $this->call($client, 'PUT', $host, '/api/v1/hr/settings/locales', ['locales' => ['fr'], 'defaultLocale' => 'fr']);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'PUT', $host, '/api/v1/hr/settings/locales', ['locales' => ['en'], 'defaultLocale' => 'sv']);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'PUT', $host, '/api/v1/hr/settings/locales', ['locales' => ['en', 'sv'], 'defaultLocale' => 'sv']);
        self::assertResponseIsSuccessful();
        $this->call($client, 'PATCH', $host, '/api/v1/hr/branding', ['displayName' => 'Acme Attendance']);
        self::assertResponseIsSuccessful();
        $this->call($client, 'GET', $host, '/api/v1/auth/permissions');
        self::assertResponseIsSuccessful();
        $permissions = $this->decode($client->getResponse()->getContent());
        self::assertContains('permissions.manage', $permissions['data']['permissions']);
        self::assertContains('branding.manage', $permissions['data']['catalog']['tenant_admin']);
        self::assertNotContains('branding.manage', $permissions['data']['catalog']['hr']);
        $this->call($client, 'PUT', $host, '/api/v1/hr/users/'.$admin->id.'/roles', ['roles' => ['employee']]);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'POST', $host, '/api/v1/hr/users/'.$admin->id.'/disable');
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'POST', $host, '/api/v1/hr/users', [
            'email' => 'new@example.com',
            'displayName' => 'Nia',
            'password' => 'short-pass',
            'roles' => ['employee'],
        ]);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'POST', $host, '/api/v1/hr/users', [
            'email' => 'new@example.com',
            'displayName' => 'Nia',
            'password' => self::PASSWORD,
            'roles' => ['platform_admin'],
        ]);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'POST', $host, '/api/v1/hr/users', [
            'email' => 'employee@example.com',
            'displayName' => 'Duplicate',
            'password' => self::PASSWORD,
            'roles' => ['employee'],
        ]);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'PUT', $host, '/api/v1/hr/users/'.$employee->id.'/roles', ['roles' => ['manager']]);
        self::assertResponseIsSuccessful();
        $this->call($client, 'GET', $host, '/api/v1/hr/reports', null, $employeeCookie);
        self::assertResponseIsSuccessful();
        self::assertSame('team', $this->decode($client->getResponse()->getContent())['data']['scope']);
        $this->call($client, 'POST', $host, '/api/v1/hr/users/'.$hr->id.'/disable');
        self::assertResponseIsSuccessful();

        $this->login($client, $host, '/api/v1/auth/login', 'hr@example.com', self::PASSWORD);
        self::assertResponseStatusCodeSame(401);
        self::assertSame($wrongPassword = 'invalid_credentials', $this->problem($client)['code']);
        $this->call($client, 'GET', $host, '/api/v1/me/profile', null, $managerCookie);
        self::assertResponseIsSuccessful();
        unset($wrongPassword);
    }

    public function testASessionCannotCrossTenantsAndADisabledTokenStopsWorking(): void
    {
        [$client, $alphaHost] = $this->openTenant('alpha');
        [, $betaHost] = $this->openTenant('beta', $client);
        $this->accounts()->tenantUser($this->slug($alphaHost), 'ada@example.com', 'Ada', self::PASSWORD, ['employee']);
        $this->accounts()->tenantUser($this->slug($alphaHost), 'admin@example.com', 'Admin', self::PASSWORD, ['tenant_admin']);
        $this->accounts()->tenantUser($this->slug($betaHost), 'bo@example.com', 'Bo', self::PASSWORD, ['tenant_admin']);

        $this->login($client, $alphaHost, '/api/v1/auth/login', 'ada@example.com', self::PASSWORD);
        $alphaCookie = $this->cookieHeader($client);
        $this->call($client, 'GET', $betaHost, '/api/v1/me/profile', null, $alphaCookie);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('tenant_mismatch', $this->problem($client)['code']);
        $this->call($client, 'GET', $alphaHost, '/api/v1/me/profile', null, $alphaCookie);
        self::assertResponseStatusCodeSame(401);

        $this->login($client, $alphaHost, '/api/v1/auth/login', 'ada@example.com', self::PASSWORD);
        $alphaCookie = $this->cookieHeader($client);
        $this->call($client, 'GET', $alphaHost, '/api/v1/me/profile', ['tenant_id' => 'beta'], null, [
            'HTTP_X_TENANT_ID' => 'beta',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('tenant_mismatch', $this->problem($client)['code']);

        $this->login($client, $alphaHost, '/api/v1/auth/login', 'ada@example.com', self::PASSWORD);
        $alphaCookie = $this->cookieHeader($client);
        $this->login($client, $alphaHost, '/api/v1/auth/login', 'admin@example.com', self::PASSWORD);
        $employee = $this->findUserId($client, $alphaHost, 'ada@example.com');
        $this->call($client, 'POST', $alphaHost, '/api/v1/hr/users/'.$employee.'/disable');
        self::assertResponseIsSuccessful();
        $this->call($client, 'GET', $alphaHost, '/api/v1/me/profile', null, $alphaCookie);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('session_expired', $this->problem($client)['code']);
        $this->login($client, $alphaHost, '/api/v1/auth/login', 'ada@example.com', self::PASSWORD);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_credentials', $this->problem($client)['code']);

        $this->login($client, $betaHost, '/api/v1/auth/login', 'bo@example.com', self::PASSWORD);
        $this->call($client, 'GET', $betaHost, '/api/v1/hr/employees');
        self::assertResponseIsSuccessful();
        $names = array_column($this->decode($client->getResponse()->getContent())['data'], 'displayName');
        self::assertNotContains('Ada', $names);
        self::assertContains('Bo', $names);
    }

    public function testPlatformAdministrationStaysOnThePlatformHost(): void
    {
        [$client, $host] = $this->openTenant('plat');
        $this->accounts()->tenantUser($this->slug($host), 'admin@example.com', 'Ada', self::PASSWORD, ['tenant_admin']);
        $enrollment = self::getContainer()->get(DeviceEnrollment::class);
        self::assertInstanceOf(DeviceEnrollment::class, $enrollment);
        $enrollment->register($this->slug($host), 'Front desk', 'cli');
        $beta = $this->provision('beta'.substr($this->slug($host), 4), 'beta'.substr($host, 4));
        $enrollment->register($beta->slug(), 'Beta desk', 'cli');
        $platformEmail = 'platform-'.$this->slug($host).'@example.com';
        $this->accounts()->platformAdmin($platformEmail, 'Platform Admin', self::PASSWORD);

        $this->login($client, 'localhost', '/api/v1/platform/login', $platformEmail, 'wrong-password-value');
        self::assertResponseStatusCodeSame(401);
        $wrong = $this->problem($client);
        $this->login($client, 'localhost', '/api/v1/platform/login', 'nobody-'.$this->slug($host).'@example.com', self::PASSWORD);
        self::assertSame($wrong, $this->problem($client));

        $this->login($client, 'localhost', '/api/v1/platform/login', $platformEmail, self::PASSWORD);
        self::assertResponseIsSuccessful();
        $platformCookie = $this->cookieHeader($client);
        self::assertSame('platform', $this->decode($client->getResponse()->getContent())['data']['kind']);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/tenants');
        self::assertResponseIsSuccessful();
        $listed = $this->decode($client->getResponse()->getContent())['data'];
        self::assertNotEmpty($listed);
        self::assertArrayHasKey('slug', $listed[0]);
        self::assertArrayHasKey('status', $listed[0]);
        self::assertArrayNotHasKey('database', $listed[0]);
        self::assertArrayNotHasKey('password', $listed[0]);
        $encoded = (string) json_encode($listed);
        self::assertStringNotContainsString('password', $encoded);
        self::assertStringNotContainsString(self::PASSWORD, $encoded);

        $this->call($client, 'GET', $host, '/api/v1/hr/employees', null, $platformCookie);
        self::assertResponseStatusCodeSame(401);
        $this->call($client, 'GET', $host, '/api/v1/me/profile', null, $platformCookie);
        self::assertResponseStatusCodeSame(401);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/tenants', null, $platformCookie);
        self::assertResponseIsSuccessful();

        $this->login($client, $host, '/api/v1/auth/login', 'admin@example.com', self::PASSWORD);
        $tenantCookie = $this->cookieHeader($client);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/tenants', null, $tenantCookie);
        self::assertResponseStatusCodeSame(401);
        $this->call($client, 'GET', $host, '/api/v1/hr/terminals');
        self::assertResponseIsSuccessful();
        $terminals = $this->decode($client->getResponse()->getContent())['data'];
        self::assertSame(['Front desk'], array_column($terminals, 'displayName'));
        $this->call($client, 'GET', $host, '/api/v1/platform/diagnostics', null, $tenantCookie);
        self::assertResponseStatusCodeSame(401);
        $this->call($client, 'GET', 'localhost', '/api/v1/terminal/diagnostics');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('unauthenticated', $this->problem($client)['code']);

        $client->getCookieJar()->clear();
        $this->call($client, 'GET', 'localhost', '/api/v1/auth/permissions');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('session_expired', $this->problem($client)['code']);
    }

    public function testPlatformAdminCommandRejectsAMissingPassword(): void
    {
        $client = self::createClient();
        $application = new Application(self::$kernel);
        $email = 'ops-'.bin2hex(random_bytes(3)).'@example.com';
        $tester = new CommandTester($application->find('app:platform:create-admin'));
        self::assertSame(1, $tester->execute([
            'email' => $email,
            'display-name' => 'Ops',
        ]));
        self::assertSame(0, $tester->execute([
            'email' => $email,
            'display-name' => 'Ops',
            '--password' => self::PASSWORD,
        ]));
        $this->login($client, 'localhost', '/api/v1/platform/login', $email, self::PASSWORD);
        self::assertResponseIsSuccessful();
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/tenants');
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array{0: KernelBrowser, 1: string}
     */
    private function openTenant(string $prefix, ?KernelBrowser $client = null): array
    {
        $client ??= self::createClient();
        $suffix = bin2hex(random_bytes(3));
        $host = $prefix.$suffix.'.localhost';
        $this->provision($prefix.$suffix, $host);

        return [$client, $host];
    }

    private function provision(string $slug, string $hostname): Tenant
    {
        $provision = self::getContainer()->get(ProvisionTenant::class);
        self::assertInstanceOf(ProvisionTenant::class, $provision);

        return $provision->execute(new ProvisionTenantRequest($slug, 'Example AB', $hostname, 'Europe/Stockholm', 'en', 'cli'));
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

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $server
     */
    private function login(KernelBrowser $client, string $host, string $path, string $email, string $password, array $body = [], array $server = []): void
    {
        $client->getCookieJar()->clear();
        $this->call($client, 'POST', $host, $path, ['email' => $email, 'password' => $password, ...$body], null, $server);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $server
     */
    private function call(KernelBrowser $client, string $method, string $host, string $path, ?array $body = null, ?Cookie $cookie = null, array $server = []): void
    {
        $server['HTTP_HOST'] = $host;
        $server['HTTP_ACCEPT'] = 'application/json';
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
        $set = $client->getResponse()->headers->get('Set-Cookie');
        self::assertIsString($set);
        $lower = strtolower($set);
        self::assertStringContainsString('httponly', $lower);
        self::assertStringContainsString('samesite=lax', $lower);
        $cookies = $client->getCookieJar()->all();
        self::assertNotEmpty($cookies);

        return $cookies[array_key_last($cookies)];
    }

    private function findUserId(KernelBrowser $client, string $host, string $email): string
    {
        $this->call($client, 'GET', $host, '/api/v1/hr/users');
        self::assertResponseIsSuccessful();
        foreach ($this->decode($client->getResponse()->getContent())['data'] as $user) {
            if ($user['email'] === $email) {
                return $user['id'];
            }
        }
        self::fail('The user was not listed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function problem(KernelBrowser $client): array
    {
        $payload = $this->decode($client->getResponse()->getContent());
        unset($payload['requestId']);

        return $payload;
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
