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

final class AttendanceCorrectionApiTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    public function testACorrectionIsReviewedWithoutRewritingTheOriginalEvent(): void
    {
        $client = self::createClient();
        $host = $this->provision('correct');
        $slug = $this->slug($host);
        $ada = $this->accounts()->tenantUser($slug, 'ada-correct@example.com', 'Ada Correct', self::PASSWORD, ['employee']);
        $other = $this->accounts()->tenantUser($slug, 'otto-correct@example.com', 'Otto Correct', self::PASSWORD, ['employee']);
        $manager = $this->accounts()->tenantUser($slug, 'mia-correct@example.com', 'Mia Correct', self::PASSWORD, ['manager']);
        $this->accounts()->tenantUser($slug, 'hr-correct@example.com', 'Hanna Correct', self::PASSWORD, ['hr']);
        self::assertInstanceOf(AccountRecord::class, $ada);
        self::assertInstanceOf(AccountRecord::class, $other);
        self::assertInstanceOf(AccountRecord::class, $manager);
        $adaId = $ada->employeeId;
        $otherId = $other->employeeId;
        $managerId = $manager->employeeId;
        self::assertIsString($adaId);
        self::assertIsString($otherId);
        self::assertIsString($managerId);
        $eventId = $this->seedOpenShift($host, $adaId);

        $this->login($client, $host, 'ada-correct@example.com');
        $this->call($client, 'POST', $host, '/api/v1/me/attendance-corrections', [
            'localDate' => '2026-10-07',
            'localTime' => '07:00',
            'action' => 'clock_out',
            'reason' => 'Too early',
        ]);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'POST', $host, '/api/v1/me/attendance-corrections', [
            'localDate' => '2099-01-01',
            'localTime' => '08:00',
            'action' => 'clock_in',
            'reason' => 'Tomorrow',
        ]);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'POST', $host, '/api/v1/me/attendance-corrections', [
            'reason' => 'Missing the required change',
        ]);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'POST', $host, '/api/v1/me/attendance-corrections', [
            'localDate' => '2026-10-07',
            'localTime' => '17:00',
            'action' => 'clock_out',
            'reason' => 'Missed clock-out',
            'comment' => 'Forgot the terminal',
            'supportingInformation' => 'Gate pass 441',
            'employeeId' => $otherId,
            'tenantId' => $this->slug($host),
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->data($client);
        self::assertSame('requested', $created['status']);
        self::assertIsString($created['id']);
        $correctionId = $created['id'];

        $this->call($client, 'GET', $host, '/api/v1/me/attendance-corrections');
        self::assertResponseIsSuccessful();
        $mine = $this->decode($client)['data'];
        self::assertIsArray($mine);
        self::assertCount(1, $mine);
        self::assertIsArray($mine[0]);
        self::assertSame($adaId, $mine[0]['employeeId']);
        self::assertSame('2026-10-07', $mine[0]['localDate']);
        self::assertSame('17:00', $mine[0]['localTime']);
        self::assertSame('clock_out', $mine[0]['requestedAction']);
        self::assertSame('2026-10-07T15:00:00Z', $mine[0]['requestedOccurredAt']);
        self::assertSame('Forgot the terminal', $mine[0]['comment']);
        self::assertSame('Gate pass 441', $mine[0]['supportingInformation']);
        self::assertSame('requested', $mine[0]['status']);
        self::assertIsArray($mine[0]['originalEvents']);
        self::assertSame($eventId, $mine[0]['originalEvents'][0]['id']);
        self::assertSame('event', $mine[0]['originalEvents'][0]['origin']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $mine[0]['createdAt']);

        $this->call($client, 'POST', $host, '/api/v1/me/attendance-corrections', [
            'localDate' => '2026-10-07',
            'localTime' => '17:00',
            'action' => 'clock_out',
            'reason' => 'Duplicate',
        ]);
        self::assertResponseStatusCodeSame(409);
        $this->call($client, 'POST', $host, '/api/v1/hr/attendance-corrections/'.$correctionId.'/decision', ['decision' => 'approved']);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'POST', $host, '/api/v1/me/attendance-corrections', [
            'localDate' => '2026-10-07',
            'localTime' => '17:00',
            'action' => 'clock_out',
            'reason' => 'Missed clock-out',
            'tenantId' => 'someone-else',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->login($client, $host, 'otto-correct@example.com');
        $this->call($client, 'POST', $host, '/api/v1/me/attendance-corrections', [
            'localDate' => '2020-01-06',
            'localTime' => '08:00',
            'action' => 'clock_in',
            'reason' => 'Other team',
        ]);
        self::assertResponseStatusCodeSame(201);
        $otherCorrection = $this->data($client)['id'];
        self::assertIsString($otherCorrection);

        $this->login($client, $host, 'mia-correct@example.com');
        $this->call($client, 'POST', $host, '/api/v1/hr/attendance-corrections/'.$correctionId.'/decision', ['decision' => 'approved']);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'GET', $host, '/api/v1/hr/attendance-corrections');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decode($client)['data']);

        $this->login($client, $host, 'hr-correct@example.com');
        $this->call($client, 'POST', $host, '/api/v1/hr/manager-assignments', [
            'managerEmployeeId' => $managerId,
            'employeeId' => $adaId,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->login($client, $host, 'mia-correct@example.com');
        $this->call($client, 'POST', $host, '/api/v1/hr/attendance-corrections/'.$otherCorrection.'/decision', ['decision' => 'rejected']);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'POST', $host, '/api/v1/hr/attendance-corrections/'.$correctionId.'/decision', [
            'decision' => 'approved',
            'comment' => 'Checked the roster',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('approved', $this->data($client)['status']);
        $this->call($client, 'POST', $host, '/api/v1/hr/attendance-corrections/'.$correctionId.'/decision', ['decision' => 'rejected']);
        self::assertResponseStatusCodeSame(409);

        $this->login($client, $host, 'ada-correct@example.com');
        $this->call($client, 'GET', $host, '/api/v1/me/attendance');
        self::assertResponseIsSuccessful();
        $attendance = $this->data($client);
        self::assertIsArray($attendance['records']);
        $occurred = [];
        foreach ($attendance['records'] as $record) {
            self::assertIsArray($record);
            self::assertIsString($record['occurredAt'] ?? null);
            $occurred[] = $record['occurredAt'];
        }
        self::assertSame(['2026-10-07T06:42:15Z', '2026-10-07T15:00:00Z'], $occurred);
        $this->call($client, 'GET', $host, '/api/v1/me/dashboard?display=tenant&date=2026-10-07');
        self::assertResponseIsSuccessful();
        $day = $this->data($client);
        self::assertSame('off', $day['state']);
        self::assertFalse($day['missingClockOut']);
        self::assertSame(29865, $day['workedSeconds']);

        $this->login($client, $host, 'hr-correct@example.com');
        $this->call($client, 'POST', $host, '/api/v1/hr/attendance-corrections/'.$otherCorrection.'/decision', ['decision' => 'rejected']);
        self::assertResponseIsSuccessful();
        $this->call($client, 'GET', $host, '/api/v1/hr/attendance-corrections');
        self::assertResponseIsSuccessful();
        $reviewed = $this->decode($client)['data'];
        self::assertIsArray($reviewed);
        $approved = null;
        foreach ($reviewed as $row) {
            self::assertIsArray($row);
            if (($row['id'] ?? null) === $correctionId) {
                $approved = $row;
            }
        }
        self::assertIsArray($approved);
        self::assertSame('approved', $approved['status']);
        self::assertSame('Checked the roster', $approved['decisionComment']);
        self::assertIsString($approved['decidedByUserId']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $approved['decidedAt']);
        $this->call($client, 'GET', $host, '/api/v1/hr/audit');
        self::assertResponseIsSuccessful();
        $audit = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('attendance.correction_requested', $audit);
        self::assertStringContainsString('attendance.correction_decided', $audit);
        self::assertStringContainsString($correctionId, $audit);
        self::assertStringNotContainsString('Gate pass 441', $audit);
        self::assertStringNotContainsString('Forgot the terminal', $audit);

        $this->assertOriginalEventUntouched($host, $eventId, $adaId);
    }

    private function seedOpenShift(string $host, string $employeeId): string
    {
        $switcher = $this->switcher();
        $switcher->switchTo($this->tenant($host)->id());
        try {
            $connection = $this->connection();
            $eventId = Uuid::v7()->toRfc4122();
            $sessionId = Uuid::v7()->toRfc4122();
            $connection->insert('attendance_events', [
                'id' => $eventId,
                'session_id' => $sessionId,
                'employee_id' => $employeeId,
                'event_type' => 'clock_in',
                'occurred_at' => '2026-10-07 06:42:15',
            ]);
            $connection->insert('attendance_sessions', [
                'id' => $sessionId,
                'employee_id' => $employeeId,
                'state' => 'working',
                'opened_at' => '2026-10-07 06:42:15',
                'closed_at' => null,
                'open_employee_id' => $employeeId,
            ]);

            return $eventId;
        } finally {
            $switcher->reset();
        }
    }

    private function assertOriginalEventUntouched(string $host, string $eventId, string $employeeId): void
    {
        $switcher = $this->switcher();
        $switcher->switchTo($this->tenant($host)->id());
        try {
            $connection = $this->connection();
            $event = $connection->fetchAssociative('SELECT event_type, occurred_at FROM attendance_events WHERE id = ?', [$eventId]);
            self::assertIsArray($event);
            self::assertSame('clock_in', $event['event_type']);
            self::assertSame('2026-10-07 06:42:15', $event['occurred_at']);
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM attendance_events WHERE employee_id = ?', [$employeeId]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM attendance_adjustments WHERE employee_id = ?', [$employeeId]));
            $session = $connection->fetchAssociative('SELECT state, open_employee_id, closed_at FROM attendance_sessions WHERE employee_id = ?', [$employeeId]);
            self::assertIsArray($session);
            self::assertSame('off', $session['state']);
            self::assertNull($session['open_employee_id']);
            self::assertSame('2026-10-07 15:00:00', $session['closed_at']);
        } finally {
            $switcher->reset();
        }
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
    private function decode(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(KernelBrowser $client): array
    {
        $data = $this->decode($client)['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
