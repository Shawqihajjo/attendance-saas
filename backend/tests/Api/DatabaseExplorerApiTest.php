<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Identity\Application\OpenAccounts;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class DatabaseExplorerApiTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    public function testPlatformAdminCanBrowseSchemaAndRowsWithoutSecretsOrCrossTenantRows(): void
    {
        $client = self::createClient();
        $north = 'north'.bin2hex(random_bytes(3));
        $south = 'south'.bin2hex(random_bytes(3));
        $platform = $this->platformCookie($client, $north);
        $this->createTenant($client, $platform, $north, 'North Explorer');
        $northTenant = $this->decode($client->getResponse()->getContent())['data'];
        $this->createTenant($client, $platform, $south, 'South Explorer');

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases', null, $platform);
        self::assertResponseIsSuccessful();
        $catalog = $this->decode($client->getResponse()->getContent());
        $encoded = json_encode($catalog, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('password', strtolower($encoded));
        self::assertStringNotContainsString('databaseName', $encoded);
        self::assertStringNotContainsString('host', strtolower($encoded));
        $targets = array_column($catalog['data'], 'target');
        self::assertContains('platform', $targets);
        self::assertContains($north, $targets);
        self::assertContains($south, $targets);
        self::assertArrayHasKey('durationMs', $catalog['meta']);

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/platform/tables', null, $platform);
        self::assertResponseIsSuccessful();
        $platformTables = array_column($this->decode($client->getResponse()->getContent())['data'], 'name');
        self::assertContains('tenants', $platformTables);
        self::assertNotContains('tenant_database_connections', $platformTables);
        self::assertNotContains('platform_credentials', $platformTables);

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$north.'/tables', null, $platform);
        self::assertResponseIsSuccessful();
        $tables = $this->decode($client->getResponse()->getContent())['data'];
        $names = array_column($tables, 'name');
        self::assertContains('employees', $names);
        self::assertNotContains('user_credentials', $names);
        self::assertNotContains('employee_codes', $names);
        self::assertNotContains('fingerprint_enrollments', $names);
        $employees = null;
        foreach ($tables as $table) {
            if ($table['name'] === 'employees') {
                $employees = $table;
            }
        }
        self::assertIsArray($employees);
        self::assertNull($employees['rowEstimate']);

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$north.'/tables/employees', null, $platform);
        self::assertResponseIsSuccessful();
        $detail = $this->decode($client->getResponse()->getContent())['data'];
        $columns = array_column($detail['columns'], 'name');
        self::assertContains('display_name', $columns);
        self::assertNotContains('password_hash', $columns);
        $primary = false;
        foreach ($detail['columns'] as $column) {
            if ($column['name'] === 'id') {
                $primary = $column['primaryKey'];
            }
        }
        self::assertTrue($primary);
        self::assertIsArray($detail['indexes']);
        self::assertIsArray($detail['foreignKeys']);

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$north.'/tables/employees/rows', null, $platform);
        self::assertResponseIsSuccessful();
        $page = $this->decode($client->getResponse()->getContent());
        self::assertSame(50, $page['meta']['page']['limit']);
        self::assertSame(0, $page['meta']['page']['offset']);
        self::assertArrayNotHasKey('total', $page['meta']['page']);
        self::assertTrue($page['meta']['page']['returned'] >= 1);
        self::assertFalse($page['meta']['page']['hasMore']);
        self::assertSame('North Explorer', $page['data']['rows'][0]['display_name']);

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$north.'/tables/employees/rows?limit=101', null, $platform);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$north.'/tables/employees/rows?sort=not_a_column', null, $platform);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$north.'/tables/employees/rows?sort=password_hash', null, $platform);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$north.'/tables/employees/rows?columns=password_hash', null, $platform);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$north.'/tables/employees/rows?sql=select+1', null, $platform);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$north.'/tables/employees/rows?database=platform', null, $platform);
        self::assertResponseStatusCodeSame(422);

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$north.'/tables/employees/rows?'.http_build_query([
            'filter' => ['status' => "active' OR '1'='1"],
        ]), null, $platform);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->decode($client->getResponse()->getContent())['meta']['page']['returned']);

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$north.'/tables/employees/rows?'.http_build_query([
            'filter' => ['status' => 'active'],
            'columns' => 'display_name',
            'q' => 'North',
            'sort' => 'display_name',
            'direction' => 'asc',
        ]), null, $platform);
        self::assertResponseIsSuccessful();
        $filtered = $this->decode($client->getResponse()->getContent());
        self::assertSame(['display_name'], $filtered['data']['columns']);
        self::assertSame('North Explorer', $filtered['data']['rows'][0]['display_name']);
        self::assertArrayNotHasKey('status', $filtered['data']['rows'][0]);

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$north.'/tables/user_credentials', null, $platform);
        self::assertResponseStatusCodeSame(404);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/platform/tables/tenant_database_connections/rows', null, $platform);
        self::assertResponseStatusCodeSame(404);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$northTenant['id'].'/tables', null, $platform);
        self::assertResponseStatusCodeSame(404);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$north.'/tables', null, $platform);
        self::assertResponseIsSuccessful();

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$south.'/tables/employees/rows?columns=display_name', null, $platform);
        self::assertResponseIsSuccessful();
        $southRows = json_encode($this->decode($client->getResponse()->getContent()), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('South Explorer', $southRows);
        self::assertStringNotContainsString('North Explorer', $southRows);

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$south.'/suspend', null, $platform);
        self::assertResponseIsSuccessful();
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases', null, $platform);
        self::assertContains($south, array_column($this->decode($client->getResponse()->getContent())['data'], 'target'));
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants/'.$south.'/archive', ['confirmation' => $south], $platform);
        self::assertResponseIsSuccessful();
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases', null, $platform);
        self::assertNotContains($south, array_column($this->decode($client->getResponse()->getContent())['data'], 'target'));
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$south.'/tables', null, $platform);
        self::assertResponseStatusCodeSame(404);

        $this->login($client, $north.'.localhost', '/api/v1/auth/login', 'admin@'.$north.'.example', self::PASSWORD);
        $admin = $this->cookieHeader($client);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases', null, $admin);
        self::assertResponseStatusCodeSame(401);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases', null, null);
        self::assertResponseStatusCodeSame(401);

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/audit?tenant='.$north, null, $platform);
        self::assertResponseIsSuccessful();
        $seen = null;
        foreach ($this->decode($client->getResponse()->getContent())['data'] as $entry) {
            if ($entry['action'] === 'database.rows_viewed') {
                $seen = $entry;
            }
        }
        self::assertIsArray($seen);
        self::assertSame('success', $seen['result']);
        self::assertArrayHasKey('durationMs', $seen['metadata']);
        self::assertSame('employees', $seen['metadata']['table']);
        self::assertArrayNotHasKey('password', $seen['metadata']);
        self::assertStringNotContainsString('North Explorer', json_encode($seen, JSON_THROW_ON_ERROR));

        $connection = self::getContainer()->get('doctrine.dbal.platform_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $stored = $connection->fetchAssociative(
            "SELECT request_id, metadata FROM platform_audit_log WHERE action = 'database.rows_viewed' AND tenant_id = ? ORDER BY occurred_at DESC LIMIT 1",
            [$northTenant['id']],
        );
        self::assertIsArray($stored);
        self::assertIsString($stored['request_id']);
        self::assertNotSame('', $stored['request_id']);
        self::assertStringNotContainsString('North Explorer', (string) $stored['metadata']);
    }

    public function testQueryEndpointRunsASelectAndRefusesWritesAndProtectedNames(): void
    {
        $client = self::createClient();
        $slug = 'query'.bin2hex(random_bytes(3));
        $platform = $this->platformCookie($client, $slug);
        $this->createTenant($client, $platform, $slug, 'Query Person');
        $tenant = $this->decode($client->getResponse()->getContent())['data'];

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/databases/'.$slug.'/query', [
            'sql' => 'SELECT display_name FROM employees',
        ], $platform);
        self::assertResponseIsSuccessful();
        $page = $this->decode($client->getResponse()->getContent());
        self::assertSame(['display_name'], $page['data']['columns']);
        self::assertSame('Query Person', $page['data']['rows'][0]['display_name']);
        self::assertSame(1000, $page['meta']['page']['limit']);
        self::assertFalse($page['meta']['page']['truncated']);
        self::assertArrayHasKey('durationMs', $page['meta']);

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/databases/'.$slug.'/query', [
            'sql' => 'DELETE FROM employees',
        ], $platform);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('query_rejected', $this->decode($client->getResponse()->getContent())['code']);
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/databases/'.$slug.'/query', [
            'sql' => 'SELECT password_hash FROM user_credentials',
            'database' => 'platform',
        ], $platform);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$slug.'/tables/employees/rows?columns=display_name', null, $platform);
        self::assertResponseIsSuccessful();
        self::assertSame('Query Person', $this->decode($client->getResponse()->getContent())['data']['rows'][0]['display_name']);

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/audit?tenant='.$slug, null, $platform);
        self::assertResponseIsSuccessful();
        $entry = null;
        foreach ($this->decode($client->getResponse()->getContent())['data'] as $row) {
            if ($row['action'] === 'database.query_executed' && $row['result'] === 'success') {
                $entry = $row;
            }
        }
        self::assertIsArray($entry);
        self::assertArrayNotHasKey('sql', $entry['metadata']);
        self::assertSame($tenant['id'], $entry['tenantId']);
        self::assertStringNotContainsString('Query Person', json_encode($entry, JSON_THROW_ON_ERROR));

        $connection = self::getContainer()->get('doctrine.dbal.platform_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $stored = $connection->fetchAssociative(
            "SELECT metadata FROM platform_audit_log WHERE action = 'database.query_executed' AND tenant_id = ? AND result = 'success' ORDER BY occurred_at DESC LIMIT 1",
            [$tenant['id']],
        );
        self::assertIsArray($stored);
        $metadata = json_decode((string) $stored['metadata'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        self::assertSame('select', $metadata['statement']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $metadata['queryHash']);
        self::assertArrayNotHasKey('sql', $metadata);
        self::assertStringNotContainsString('display_name', (string) $stored['metadata']);
    }

    public function testReadOnlyConsoleRejectsBypassAttemptsAndTruncatesLargeReads(): void
    {
        $client = self::createClient();
        $slug = 'console'.bin2hex(random_bytes(3));
        $platform = $this->platformCookie($client, $slug);
        $this->createTenant($client, $platform, $slug, 'Console Person');

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/databases/'.$slug.'/query', [
            'sql' => 'WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM c WHERE x < 1500) SELECT x FROM c',
        ], $platform);
        self::assertResponseIsSuccessful();
        $page = $this->decode($client->getResponse()->getContent());
        self::assertCount(1000, $page['data']['rows']);
        self::assertTrue($page['meta']['page']['truncated']);
        self::assertSame(1, $page['data']['rows'][0]['x']);

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/databases/'.$slug.'/query', [
            'sql' => 'SHOW TABLES',
        ], $platform);
        self::assertResponseIsSuccessful();
        $names = array_column($this->decode($client->getResponse()->getContent())['data']['rows'], 'name');
        self::assertContains('employees', $names);
        self::assertNotContains('user_credentials', $names);

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/databases/'.$slug.'/query', [
            'sql' => 'DESCRIBE employees',
        ], $platform);
        self::assertResponseIsSuccessful();
        $columns = array_column($this->decode($client->getResponse()->getContent())['data']['rows'], 'name');
        self::assertContains('display_name', $columns);
        self::assertNotContains('password_hash', $columns);

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/databases/'.$slug.'/query', [
            'sql' => 'SHOW COLUMNS FROM employees',
        ], $platform);
        self::assertResponseIsSuccessful();
        self::assertContains('display_name', array_column($this->decode($client->getResponse()->getContent())['data']['rows'], 'name'));

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/databases/'.$slug.'/query', [
            'sql' => "SELECT printf('%.*c', 2000, 65) AS wide",
        ], $platform);
        self::assertResponseIsSuccessful();
        $wide = $this->decode($client->getResponse()->getContent())['data']['rows'][0]['wide'];
        self::assertIsString($wide);
        self::assertSame(400, strlen($wide));

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/databases/'.$slug.'/query', [
            'sql' => 'EXPLAIN SELECT display_name FROM employees',
        ], $platform);
        self::assertResponseIsSuccessful();
        $explained = json_encode($this->decode($client->getResponse()->getContent()), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('password_hash', $explained);
        self::assertStringNotContainsString('user_credentials', $explained);

        $this->call($client, 'POST', 'localhost', '/api/v1/platform/databases/'.$slug.'/query', [
            'sql' => 'SELECT display_name FROM employees /* SELECT password_hash FROM user_credentials */',
        ], $platform);
        self::assertResponseIsSuccessful();
        $commented = $this->decode($client->getResponse()->getContent());
        self::assertSame(['display_name'], $commented['data']['columns']);
        self::assertSame('Console Person', $commented['data']['rows'][0]['display_name']);

        foreach ([
            'SELECT display_name FROM employees; DROP TABLE employees',
            'SELECT * FROM (SELECT password_hash FROM user_credentials) hidden_rows',
            'SELECT display_name FROM employees UNION SELECT password_hash FROM user_credentials',
            'SELECT * FROM user_credentials',
            'SELECT password_hash FROM employees',
            'SELECT display_name FROM tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.employees',
            'DROP TABLE employees',
            'UPDATE employees SET display_name = \'changed\'',
            'SHOW PROCESSLIST',
        ] as $sql) {
            $this->call($client, 'POST', 'localhost', '/api/v1/platform/databases/'.$slug.'/query', ['sql' => $sql], $platform);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('query_rejected', $this->decode($client->getResponse()->getContent())['code']);
        }

        $this->call($client, 'GET', 'localhost', '/api/v1/platform/databases/'.$slug.'/tables/employees/rows?columns=display_name', null, $platform);
        self::assertResponseIsSuccessful();
        self::assertSame('Console Person', $this->decode($client->getResponse()->getContent())['data']['rows'][0]['display_name']);
    }

    public function testQueryEndpointRejectsAnonymousAndTenantSessions(): void
    {
        $client = self::createClient();
        $slug = 'closed'.bin2hex(random_bytes(3));
        $platform = $this->platformCookie($client, $slug);
        $this->createTenant($client, $platform, $slug, 'Closed Person');

        $client->getCookieJar()->clear();
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/databases/'.$slug.'/query', [
            'sql' => 'SELECT display_name FROM employees',
        ], null);
        self::assertResponseStatusCodeSame(401);

        $this->login($client, $slug.'.localhost', '/api/v1/auth/login', 'admin@'.$slug.'.example', self::PASSWORD);
        $admin = $this->cookieHeader($client);
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/databases/'.$slug.'/query', [
            'sql' => 'SELECT display_name FROM employees',
        ], $admin);
        self::assertResponseStatusCodeSame(401);
    }

    private function platformCookie(KernelBrowser $client, string $slug): Cookie
    {
        $email = 'platform-'.$slug.'@example.com';
        $this->accounts()->platformAdmin($email, 'Platform Admin', self::PASSWORD);
        $this->login($client, 'localhost', '/api/v1/platform/login', $email, self::PASSWORD);
        self::assertResponseIsSuccessful();

        return $this->cookieHeader($client);
    }

    private function createTenant(KernelBrowser $client, Cookie $platform, string $slug, string $displayName): void
    {
        $this->call($client, 'POST', 'localhost', '/api/v1/platform/tenants', [
            'legalName' => $displayName.' AB',
            'slug' => $slug,
            'timezone' => 'Europe/Stockholm',
            'defaultLocale' => 'en',
            'administrator' => [
                'email' => 'admin@'.$slug.'.example',
                'displayName' => $displayName,
                'password' => self::PASSWORD,
            ],
        ], $platform);
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
