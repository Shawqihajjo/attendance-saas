<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Identity\Application\OpenAccounts;
use App\Identity\Domain\AccountRecord;
use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Tenant\Directory\AttendanceIdentifierIssuer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EmployeeDirectoryApiTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    public function testHrManagesEmployeesAndTheAttendanceCodeIsNotAStoredSecret(): void
    {
        [$client, $host] = $this->openTenant('people');
        $slug = $this->slug($host);
        $hr = $this->accounts()->tenantUser($slug, 'hr@example.com', 'Harper HR', self::PASSWORD, ['hr']);
        $manager = $this->accounts()->tenantUser($slug, 'manager@example.com', 'Mia Manager', self::PASSWORD, ['manager']);
        $employeeUser = $this->accounts()->tenantUser($slug, 'employee@example.com', 'Eli Employee', self::PASSWORD, ['employee']);
        self::assertInstanceOf(AccountRecord::class, $hr);
        self::assertInstanceOf(AccountRecord::class, $manager);
        self::assertInstanceOf(AccountRecord::class, $employeeUser);

        $this->login($client, $host, 'employee@example.com');
        $this->call($client, 'POST', $host, '/api/v1/hr/employees', ['employeeNumber' => 'A-1', 'name' => 'Nope']);
        self::assertResponseStatusCodeSame(403);

        $this->login($client, $host, 'manager@example.com');
        $this->call($client, 'POST', $host, '/api/v1/hr/employees', ['employeeNumber' => 'A-1', 'name' => 'Nope']);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'POST', $host, '/api/v1/hr/teams', ['name' => 'Desk']);
        self::assertResponseStatusCodeSame(403);

        $this->login($client, $host, 'hr@example.com');
        $this->call($client, 'POST', $host, '/api/v1/hr/departments', ['name' => 'Operations']);
        self::assertResponseStatusCodeSame(201);
        $departmentId = $this->payload($client)['id'];
        $this->call($client, 'POST', $host, '/api/v1/hr/departments', ['name' => 'Finance']);
        $otherDepartmentId = $this->payload($client)['id'];
        $this->call($client, 'POST', $host, '/api/v1/hr/teams', ['name' => 'Floor', 'departmentId' => $departmentId]);
        self::assertResponseIsSuccessful();
        $teamId = $this->payload($client)['id'];
        $this->call($client, 'POST', $host, '/api/v1/hr/teams', ['name' => 'Accounts', 'departmentId' => $otherDepartmentId]);
        $otherTeamId = $this->payload($client)['id'];

        $this->call($client, 'POST', $host, '/api/v1/hr/employees', [
            'employeeNumber' => 'a-100',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+46 70 123 456',
            'departmentId' => $departmentId,
            'teamId' => $teamId,
            'managerEmployeeId' => $manager->employeeId,
            'scheduleName' => 'Weekdays',
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload($client);
        $code = $created['attendanceCode'];
        self::assertIsString($code);
        $issuer = self::getContainer()->get(AttendanceIdentifierIssuer::class);
        self::assertInstanceOf(AttendanceIdentifierIssuer::class, $issuer);
        $hash = $issuer->hash($code);
        self::assertSame('A-100', $created['employeeNumber']);
        self::assertSame('Ada Lovelace', $created['name']);
        self::assertSame('Operations', $created['departmentName']);
        self::assertSame('Floor', $created['teamName']);
        self::assertSame($manager->employeeId, $created['managerEmployeeId']);
        self::assertSame('Weekdays', $created['schedule']['name']);
        self::assertTrue($created['attendanceIdentifierSet']);
        self::assertSame('active', $created['status']);
        self::assertStringNotContainsString($hash, (string) $client->getResponse()->getContent());
        $employeeId = $created['id'];

        $this->call($client, 'GET', $host, '/api/v1/hr/employees/'.$employeeId);
        self::assertResponseIsSuccessful();
        $visible = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($code, $visible);
        self::assertStringNotContainsString($hash, $visible);
        self::assertArrayNotHasKey('attendanceCode', $this->payload($client));

        $this->call($client, 'GET', $host, '/api/v1/hr/audit');
        $audit = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($code, $audit);
        self::assertStringNotContainsString($hash, $audit);
        self::assertStringContainsString('employee.created', $audit);

        $this->call($client, 'POST', $host, '/api/v1/hr/employees', ['employeeNumber' => 'A-100', 'name' => 'Duplicate']);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'POST', $host, '/api/v1/hr/employees', ['employeeNumber' => 'A 100', 'name' => 'Bad Number']);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'POST', $host, '/api/v1/hr/employees', ['employeeNumber' => 'A-101', 'name' => 'Bad Email', 'email' => 'not-an-email']);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'POST', $host, '/api/v1/hr/employees', ['employeeNumber' => 'A-102', 'name' => 'Bad Phone', 'phone' => '123']);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'POST', $host, '/api/v1/hr/employees', [
            'employeeNumber' => 'A-103',
            'name' => 'Wrong Team',
            'departmentId' => $departmentId,
            'teamId' => $otherTeamId,
        ]);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'POST', $host, '/api/v1/hr/employees', [
            'employeeNumber' => 'A-104',
            'name' => 'Missing Department',
            'departmentId' => '01999999-9999-7999-8999-999999999999',
        ]);
        self::assertResponseStatusCodeSame(404);

        $this->call($client, 'PATCH', $host, '/api/v1/hr/employees/'.$employeeId, [
            'name' => 'Ada L.',
            'managerEmployeeId' => $employeeId,
        ]);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'POST', $host, '/api/v1/hr/employees/'.$employeeId.'/status', ['status' => 'inactive']);
        self::assertResponseIsSuccessful();
        self::assertSame('inactive', $this->payload($client)['status']);
        $this->call($client, 'POST', $host, '/api/v1/hr/manager-assignments', [
            'managerEmployeeId' => $employeeId,
            'employeeId' => $manager->employeeId,
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->call($client, 'POST', $host, '/api/v1/hr/employees/'.$employeeId.'/status', ['status' => 'active']);
        self::assertResponseIsSuccessful();
        $this->call($client, 'PATCH', $host, '/api/v1/hr/employees/'.$employeeId, [
            'name' => 'Ada L.',
            'departmentId' => $otherDepartmentId,
            'teamId' => $otherTeamId,
            'scheduleName' => 'Nights',
        ]);
        self::assertResponseIsSuccessful();
        $updated = $this->payload($client);
        self::assertSame('Ada L.', $updated['name']);
        self::assertSame('Finance', $updated['departmentName']);
        self::assertSame('Accounts', $updated['teamName']);
        self::assertSame('Nights', $updated['schedule']['name']);
        self::assertArrayNotHasKey('attendanceCode', $updated);

        $this->call($client, 'POST', $host, '/api/v1/hr/employees/'.$employeeId.'/attendance-code');
        self::assertResponseIsSuccessful();
        $reset = $this->payload($client)['attendanceCode'];
        self::assertIsString($reset);
        self::assertNotSame($code, $reset);
        $this->call($client, 'GET', $host, '/api/v1/hr/employees');
        $list = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($code, $list);
        self::assertStringNotContainsString($reset, $list);
        self::assertStringNotContainsString($issuer->hash($reset), $list);

        $this->login($client, $host, 'manager@example.com');
        $this->call($client, 'GET', $host, '/api/v1/hr/employees');
        self::assertResponseIsSuccessful();
        $listed = $this->payload($client);
        $visibleIds = [];
        foreach ($listed as $row) {
            if (is_array($row) && isset($row['id'])) {
                $visibleIds[] = $row['id'];
            }
        }
        self::assertSame([$employeeId], $visibleIds);
        self::assertStringNotContainsString($reset, (string) $client->getResponse()->getContent());
    }

    /**
     * @return array{0: KernelBrowser, 1: string}
     */
    private function openTenant(string $prefix): array
    {
        $client = self::createClient();
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
        return explode('.', $host)[0];
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
    private function payload(KernelBrowser $client): array
    {
        $decoded = $this->decode($client->getResponse()->getContent());
        self::assertIsArray($decoded['data']);

        return $decoded['data'];
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
