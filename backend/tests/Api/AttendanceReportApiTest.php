<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Identity\Application\OpenAccounts;
use App\Identity\Domain\AccountRecord;
use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class AttendanceReportApiTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    public function testDashboardsAndReportsHonorScopeAndTimezone(): void
    {
        $client = self::createClient();
        $host = $this->provision('report');
        $slug = $this->slug($host);
        $ada = $this->accounts()->tenantUser($slug, 'ada-report@example.com', 'Ada Report', self::PASSWORD, ['employee']);
        $nia = $this->accounts()->tenantUser($slug, 'nia-report@example.com', 'Nia Report', self::PASSWORD, ['employee']);
        $bea = $this->accounts()->tenantUser($slug, 'bea-report@example.com', 'Bea Report', self::PASSWORD, ['employee']);
        $manager = $this->accounts()->tenantUser($slug, 'manager-report@example.com', 'Mia Report', self::PASSWORD, ['manager']);
        $this->accounts()->tenantUser($slug, 'hr-report@example.com', 'Hanna Report', self::PASSWORD, ['hr']);
        self::assertInstanceOf(AccountRecord::class, $ada);
        self::assertInstanceOf(AccountRecord::class, $nia);
        self::assertInstanceOf(AccountRecord::class, $bea);
        self::assertInstanceOf(AccountRecord::class, $manager);
        $adaId = $ada->employeeId;
        $niaId = $nia->employeeId;
        $beaId = $bea->employeeId;
        $managerId = $manager->employeeId;
        self::assertIsString($adaId);
        self::assertIsString($niaId);
        self::assertIsString($beaId);
        self::assertIsString($managerId);

        $deviceId = Uuid::v7()->toRfc4122();
        $departmentId = Uuid::v7()->toRfc4122();
        $otherDepartmentId = Uuid::v7()->toRfc4122();
        $teamId = Uuid::v7()->toRfc4122();
        $this->seed($host, $deviceId, $departmentId, $otherDepartmentId, $teamId, $adaId, $niaId, $beaId);

        $this->login($client, $host, 'ada-report@example.com');
        $this->call($client, 'GET', $host, '/api/v1/hr/reports');
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'GET', $host, '/api/v1/hr/dashboard');
        self::assertResponseStatusCodeSame(403);

        $this->call($client, 'GET', $host, '/api/v1/me/dashboard?display=tenant&date=2026-10-07');
        self::assertResponseIsSuccessful();
        $stockholm = $this->data($client);
        self::assertSame('Europe/Stockholm', $stockholm['timezone']);
        self::assertSame('2026-10-07', $stockholm['date']);
        self::assertSame('off', $stockholm['state']);
        self::assertFalse($stockholm['missingClockOut']);
        self::assertSame(3600, $stockholm['workedSeconds']);
        self::assertIsArray($stockholm['clockIn']);
        self::assertIsArray($stockholm['clockOut']);
        self::assertSame('2026-10-07T06:42:15Z', $stockholm['clockIn']['occurredAt']);
        self::assertSame('2026-10-07 08:42:15', $stockholm['clockIn']['displayedAt']);
        self::assertSame('2026-10-07T07:42:15Z', $stockholm['clockOut']['occurredAt']);
        self::assertSame('2026-10-07 09:42:15', $stockholm['clockOut']['displayedAt']);
        self::assertSame([], $stockholm['lunches']);
        self::assertIsArray($stockholm['history']);
        self::assertIsArray($stockholm['history']['page']);
        self::assertSame(2, $stockholm['history']['page']['total']);

        $this->call($client, 'GET', $host, '/api/v1/me/dashboard?display=report&timezone=America/New_York&date=2026-10-07');
        self::assertResponseIsSuccessful();
        $newYork = $this->data($client);
        self::assertSame('America/New_York', $newYork['timezone']);
        self::assertSame(3600, $newYork['workedSeconds']);
        self::assertIsArray($newYork['clockIn']);
        self::assertIsArray($newYork['clockOut']);
        self::assertSame('2026-10-07T06:42:15Z', $newYork['clockIn']['occurredAt']);
        self::assertSame('2026-10-07 02:42:15', $newYork['clockIn']['displayedAt']);
        self::assertSame('2026-10-07 03:42:15', $newYork['clockOut']['displayedAt']);

        $this->call($client, 'GET', $host, '/api/v1/me/dashboard?display=report&timezone=UTC+1&date=2026-10-07');
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'POST', $host, '/api/v1/me/attendance-corrections', [
            'localDate' => '2020-01-06',
            'localTime' => '08:00',
            'action' => 'clock_in',
            'reason' => 'Missed lunch',
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->login($client, $host, 'nia-report@example.com');
        $this->call($client, 'GET', $host, '/api/v1/me/dashboard?display=tenant&date=2026-10-07');
        self::assertResponseIsSuccessful();
        $open = $this->data($client);
        self::assertSame('working', $open['state']);
        self::assertTrue($open['missingClockOut']);
        self::assertNull($open['clockOut']);
        self::assertIsArray($open['clockIn']);
        self::assertSame('2026-10-06T20:00:00Z', $open['clockIn']['occurredAt']);

        $this->login($client, $host, 'hr-report@example.com');
        $this->call($client, 'POST', $host, '/api/v1/hr/manager-assignments', [
            'managerEmployeeId' => $managerId,
            'employeeId' => $adaId,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=tenant&date=2026-10-07');
        self::assertResponseIsSuccessful();
        $day = $this->data($client);
        self::assertSame('tenant', $day['scope']);
        self::assertSame('Europe/Stockholm', $day['timezone']);
        self::assertIsArray($day['page']);
        self::assertSame(3, $day['page']['total']);
        self::assertEqualsCanonicalizing([
            '2026-10-07T06:42:15Z',
            '2026-10-07T07:42:15Z',
            '2026-10-07T10:00:00Z',
        ], $this->occurredAts($day));

        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=report&timezone=America/New_York&date=2026-10-07&employeeId='.$adaId);
        self::assertResponseIsSuccessful();
        $adaReport = $this->data($client);
        self::assertSame('America/New_York', $adaReport['timezone']);
        $adaClockIn = $this->recordAt($adaReport, '2026-10-07T06:42:15Z');
        self::assertSame('2026-10-07 02:42:15', $adaClockIn['displayedAt']);
        self::assertSame('America/New_York', $adaClockIn['timezone']);
        self::assertSame(3600, $stockholm['workedSeconds']);

        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=terminal&deviceId='.$deviceId.'&date=2026-10-07');
        self::assertResponseIsSuccessful();
        $terminal = $this->data($client);
        self::assertSame('America/Chicago', $terminal['timezone']);
        self::assertSame('2026-10-07 01:42:15', $this->recordAt($terminal, '2026-10-07T06:42:15Z')['displayedAt']);

        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=browser&browserTimezone=Asia/Tokyo&date=2026-10-07&eventType=clock_in&employeeId='.$adaId);
        self::assertResponseIsSuccessful();
        $browser = $this->data($client);
        self::assertSame('2026-10-07 15:42:15', $this->recordAt($browser, '2026-10-07T06:42:15Z')['displayedAt']);
        self::assertSame('2026-10-07T06:42:15Z', $this->recordAt($browser, '2026-10-07T06:42:15Z')['occurredAt']);

        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=tenant&date=2026-10-07&status=working');
        self::assertResponseIsSuccessful();
        self::assertSame(['2026-10-07T10:00:00Z'], $this->occurredAts($this->data($client)));

        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=tenant&date=2026-10-07&status=off&employeeId='.$adaId);
        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing([
            '2026-10-07T06:42:15Z',
            '2026-10-07T07:42:15Z',
        ], $this->occurredAts($this->data($client)));

        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=tenant&from=2026-10-07&to=2026-10-07&departmentId='.$departmentId);
        self::assertResponseIsSuccessful();
        $department = $this->data($client);
        self::assertSame([$adaId, $adaId], $this->employeeIds($department));
        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=tenant&teamId='.$teamId);
        self::assertResponseIsSuccessful();
        self::assertSame([$adaId, $adaId], $this->employeeIds($this->data($client)));
        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=tenant&departmentId='.$otherDepartmentId);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->occurredAts($this->data($client)));

        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=tenant&date=2026-10-07&limit=1&offset=0');
        self::assertResponseIsSuccessful();
        $page = $this->data($client);
        self::assertIsArray($page['page']);
        self::assertSame(1, $page['page']['limit']);
        self::assertSame(0, $page['page']['offset']);
        self::assertSame(3, $page['page']['total']);
        self::assertCount(1, $page['records']);
        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=tenant&date=2026-10-07&limit=1&offset=2');
        self::assertResponseIsSuccessful();
        $last = $this->data($client);
        self::assertCount(1, $last['records']);
        self::assertIsArray($last['page']);
        self::assertSame(2, $last['page']['offset']);

        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=report&timezone=UTC+1');
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'GET', $host, '/api/v1/hr/reports?from=2026-10-08&to=2026-10-07');
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'GET', $host, '/api/v1/hr/reports?limit=101');
        self::assertResponseStatusCodeSame(422);

        $this->call($client, 'GET', $host, '/api/v1/hr/dashboard?display=tenant&date=2026-10-07');
        self::assertResponseIsSuccessful();
        $hr = $this->data($client);
        self::assertSame('Europe/Stockholm', $hr['timezone']);
        self::assertContains($niaId, $this->bucketIds($hr, 'working'));
        self::assertContains($beaId, $this->bucketIds($hr, 'working'));
        self::assertNotContains($adaId, $this->bucketIds($hr, 'working'));
        self::assertContains($niaId, $this->exceptionIds($hr));
        self::assertNotContains($adaId, $this->exceptionIds($hr));
        self::assertSame(3600, $this->dailyWorked($hr, $adaId));
        self::assertIsArray($hr['corrections']);
        self::assertSame(1, $hr['corrections']['pending']);

        $this->login($client, $host, 'manager-report@example.com');
        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=tenant');
        self::assertResponseIsSuccessful();
        $team = $this->data($client);
        self::assertSame('team', $team['scope']);
        self::assertSame([$adaId, $adaId], $this->employeeIds($team));
        $this->call($client, 'GET', $host, '/api/v1/hr/reports?employeeId='.$beaId);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->occurredAts($this->data($client)));
        $this->call($client, 'GET', $host, '/api/v1/hr/reports?employeeId='.$niaId);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->occurredAts($this->data($client)));
        $this->call($client, 'GET', $host, '/api/v1/hr/dashboard?date=2026-10-07');
        self::assertResponseIsSuccessful();
        $teamBoard = $this->data($client);
        self::assertNotContains($niaId, $this->bucketIds($teamBoard, 'working'));
        self::assertNotContains($beaId, $this->bucketIds($teamBoard, 'working'));
        self::assertNotContains($niaId, $this->exceptionIds($teamBoard));
        self::assertSame(3600, $this->dailyWorked($teamBoard, $adaId));
        self::assertNull($this->dailyWorked($teamBoard, $beaId));
        self::assertIsArray($teamBoard['corrections']);
        self::assertSame(1, $teamBoard['corrections']['pending']);

        $otherHost = $this->provision('other');
        $this->accounts()->tenantUser($this->slug($otherHost), 'hr-other@example.com', 'Other HR', self::PASSWORD, ['hr']);
        $this->seedForeignEvent($otherHost);
        $foreignId = $this->tenant($host)->id();
        $this->login($client, $otherHost, 'hr-other@example.com');
        $this->call($client, 'GET', $otherHost, '/api/v1/hr/reports?display=tenant');
        self::assertResponseIsSuccessful();
        $foreign = $this->data($client);
        $foreignInstants = $this->occurredAts($foreign);
        self::assertContains('2026-02-02T02:02:02Z', $foreignInstants);
        self::assertNotContains('2026-10-07T06:42:15Z', $foreignInstants);
        self::assertNotContains('2026-10-06T20:00:00Z', $foreignInstants);
        $this->call($client, 'GET', $otherHost, '/api/v1/hr/reports?display=tenant&tenantId='.$foreignId);
        self::assertResponseStatusCodeSame(403);

        $this->login($client, $host, 'hr-report@example.com');
        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=tenant');
        self::assertResponseIsSuccessful();
        $home = $this->occurredAts($this->data($client));
        self::assertContains('2026-10-07T06:42:15Z', $home);
        self::assertNotContains('2026-02-02T02:02:02Z', $home);
        $this->call($client, 'GET', $host, '/api/v1/hr/reports?display=tenant&tenantId='.$this->tenant($otherHost)->id());
        self::assertResponseStatusCodeSame(403);
    }

    private function seed(
        string $host,
        string $deviceId,
        string $departmentId,
        string $otherDepartmentId,
        string $teamId,
        string $adaId,
        string $niaId,
        string $beaId,
    ): void {
        $switcher = $this->switcher();
        $switcher->switchTo($this->tenant($host)->id());
        try {
            $connection = $this->connection();
            $stamp = '2026-10-07 06:00:00';
            $connection->insert('departments', ['id' => $departmentId, 'name' => 'Floor', 'created_at' => $stamp]);
            $connection->insert('departments', ['id' => $otherDepartmentId, 'name' => 'Office', 'created_at' => $stamp]);
            $connection->insert('teams', [
                'id' => $teamId,
                'department_id' => $departmentId,
                'name' => 'Desk',
                'created_at' => $stamp,
            ]);
            $connection->update('employees', ['department_id' => $departmentId, 'team_id' => $teamId], ['id' => $adaId]);
            $connection->insert('devices', [
                'id' => $deviceId,
                'platform_device_id' => Uuid::v7()->toRfc4122(),
                'display_name' => 'Desk',
                'timezone' => 'America/Chicago',
                'created_at' => $stamp,
            ]);
            $this->insertEvent($connection, $adaId, 'clock_in', '2026-10-07 06:42:15', $deviceId);
            $this->insertEvent($connection, $adaId, 'clock_out', '2026-10-07 07:42:15', $deviceId);
            $this->insertEvent($connection, $niaId, 'clock_in', '2026-10-06 20:00:00', null);
            $this->insertEvent($connection, $beaId, 'clock_in', '2026-10-07 10:00:00', null);
        } finally {
            $switcher->reset();
        }
    }

    private function seedForeignEvent(string $host): void
    {
        $switcher = $this->switcher();
        $switcher->switchTo($this->tenant($host)->id());
        try {
            $connection = $this->connection();
            $employeeId = Uuid::v7()->toRfc4122();
            $connection->insert('employees', [
                'id' => $employeeId,
                'employee_number' => strtoupper(str_replace('-', '', $employeeId)),
                'display_name' => 'Foreign',
                'status' => 'active',
                'created_at' => '2026-02-02 02:00:00',
                'updated_at' => '2026-02-02 02:00:00',
            ]);
            $this->insertEvent($connection, $employeeId, 'clock_in', '2026-02-02 02:02:02', null);
        } finally {
            $switcher->reset();
        }
    }

    private function insertEvent(Connection $connection, string $employeeId, string $eventType, string $occurredAt, ?string $deviceId): void
    {
        $connection->insert('attendance_events', [
            'id' => Uuid::v7()->toRfc4122(),
            'session_id' => Uuid::v7()->toRfc4122(),
            'employee_id' => $employeeId,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
            'device_id' => $deviceId,
        ]);
    }

    private function provision(string $prefix): string
    {
        $suffix = bin2hex(random_bytes(3));
        $slug = $prefix.$suffix;
        $host = $slug.'.localhost';
        $provision = self::getContainer()->get(ProvisionTenant::class);
        self::assertInstanceOf(ProvisionTenant::class, $provision);
        $provision->execute(new ProvisionTenantRequest($slug, 'Example AB', $host, 'Europe/Stockholm', 'en', 'cli'));

        return $host;
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

    private function switcher(): TenantDatabaseSwitcher
    {
        $switcher = self::getContainer()->get(TenantDatabaseSwitcher::class);
        self::assertInstanceOf(TenantDatabaseSwitcher::class, $switcher);

        return $switcher;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
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
    private function data(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);
        $data = $decoded['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function occurredAts(array $data): array
    {
        $records = $data['records'] ?? null;
        self::assertIsArray($records);
        $values = [];
        foreach ($records as $record) {
            self::assertIsArray($record);
            self::assertIsString($record['occurredAt'] ?? null);
            $values[] = $record['occurredAt'];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function employeeIds(array $data): array
    {
        $records = $data['records'] ?? null;
        self::assertIsArray($records);
        $values = [];
        foreach ($records as $record) {
            self::assertIsArray($record);
            self::assertIsString($record['employeeId'] ?? null);
            $values[] = $record['employeeId'];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function recordAt(array $data, string $occurredAt): array
    {
        $records = $data['records'] ?? null;
        self::assertIsArray($records);
        foreach ($records as $record) {
            self::assertIsArray($record);
            if (($record['occurredAt'] ?? null) === $occurredAt) {
                return $record;
            }
        }
        self::fail('The report did not include the stored instant.');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function bucketIds(array $data, string $bucket): array
    {
        $rows = $data[$bucket] ?? null;
        self::assertIsArray($rows);
        $ids = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['id'] ?? null);
            $ids[] = $row['id'];
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function exceptionIds(array $data): array
    {
        $rows = $data['exceptions'] ?? null;
        self::assertIsArray($rows);
        $ids = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['id'] ?? null);
            $ids[] = $row['id'];
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dailyWorked(array $data, string $employeeId): ?int
    {
        $daily = $data['daily'] ?? null;
        self::assertIsArray($daily);
        $records = $daily['records'] ?? null;
        self::assertIsArray($records);
        foreach ($records as $record) {
            self::assertIsArray($record);
            if (($record['employeeId'] ?? null) === $employeeId) {
                self::assertIsInt($record['workedSeconds'] ?? null);

                return $record['workedSeconds'];
            }
        }

        return null;
    }
}
