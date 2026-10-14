<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\DatabaseExplorer\ExplorerConnections;
use App\Platform\DatabaseExplorer\ExplorerPolicy;
use App\Platform\DatabaseExplorer\ExplorerQueryGate;
use App\Platform\DatabaseExplorer\ExplorerQueryGuard;
use App\Platform\DatabaseExplorer\ExplorerQueryRejectedException;
use App\Platform\DatabaseExplorer\ExplorerUnavailableException;
use App\Security\TooManyAttemptsException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DatabaseExplorerTest extends TestCase
{
    public function testPolicyHidesSecretsAndKeepsOrdinaryColumns(): void
    {
        $policy = new ExplorerPolicy();

        self::assertFalse($policy->tableVisible('user_credentials'));
        self::assertFalse($policy->tableVisible('tenant_database_connections'));
        self::assertFalse($policy->tableVisible('employee_codes'));
        self::assertFalse($policy->tableVisible('sqlite_master'));
        self::assertFalse($policy->tableVisible('employees;drop'));
        self::assertTrue($policy->tableVisible('employees'));

        self::assertFalse($policy->columnVisible('password_hash'));
        self::assertFalse($policy->columnVisible('password_ciphertext'));
        self::assertFalse($policy->columnVisible('token'));
        self::assertFalse($policy->columnVisible('template_reference'));
        self::assertTrue($policy->columnVisible('token_version'));
        self::assertTrue($policy->columnVisible('display_name'));
    }

    public function testExplorerAccountMustBeConfiguredAndMustNotCopyTheApplicationMysqlAccount(): void
    {
        $empty = new ExplorerConnections('', '', 'sqlite:///platform.db', 'sqlite:///tenants', '8.4');
        try {
            $empty->assertConfigured();
            self::fail('An empty explorer URL was accepted.');
        } catch (ExplorerUnavailableException $exception) {
            self::assertSame('explorer_unavailable', $exception->problemCode());
            self::assertSame(503, $exception->getStatusCode());
        }

        $copied = new ExplorerConnections(
            'mysql://attendance:secret@mysql:3306/platform',
            'mysql://reader:other@mysql:3306/ignored',
            'mysql://attendance:secret@mysql:3306/platform',
            'mysql://tenant:secret@mysql:3306/tenant',
            '8.4',
        );
        $this->expectException(ExplorerUnavailableException::class);
        $copied->assertConfigured();
    }

    public function testSqliteExplorerUrlsMayPointAtTheSameFiles(): void
    {
        $connections = new ExplorerConnections(
            'sqlite:///var/test_platform.db',
            'sqlite:///var/tenant-databases',
            'sqlite:///var/test_platform.db',
            'sqlite:///var/test_tenant.db',
            '8.4',
        );

        $connections->assertConfigured();
        $file = dirname(__DIR__, 2).'/var/explorer_readonly_probe.sqlite';
        if (is_file($file)) {
            unlink($file);
        }
        $created = new \PDO('sqlite:'.$file);
        $created->exec('CREATE TABLE sample (id integer)');
        $created = null;
        $probe = new ExplorerConnections(
            'sqlite:///'.$file,
            'sqlite:///'.$file,
            'sqlite:///var/other-platform.db',
            'sqlite:///var/other-tenant.db',
            '8.4',
        );
        $connection = $probe->openPlatform();
        try {
            $connection->executeStatement('INSERT INTO sample (id) VALUES (1)');
            self::fail('The read-only explorer connection accepted a write.');
        } catch (\Throwable) {
            $check = new \PDO('sqlite:'.$file);
            self::assertSame(0, (int) $check->query('SELECT COUNT(*) FROM sample')->fetchColumn());
        } finally {
            $connection->close();
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testQueryGuardAllowsOneReadAndRefusesWritesAndSecrets(): void
    {
        $guard = new ExplorerQueryGuard(new ExplorerPolicy());
        $database = 'tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        $select = $guard->prepare('  SELECT display_name FROM employees; ', $database);
        self::assertSame('select', $select->kind);
        self::assertSame('SELECT display_name FROM employees LIMIT 0, 1001', $select->sql);
        self::assertSame(1000, $select->rowCap);

        $explain = $guard->prepare('EXPLAIN SELECT id FROM employees', $database);
        self::assertSame('explain', $explain->kind);
        self::assertStringContainsString('EXPLAIN SELECT id FROM employees', $explain->sql);
        self::assertStringNotContainsString('--', $explain->sql);

        $filtered = $guard->prepare("SELECT display_name FROM employees WHERE status = 'active'", $database);
        self::assertStringContainsString("status = 'active'", $filtered->sql);

        $limited = $guard->prepare('SELECT display_name FROM employees LIMIT 10', $database);
        self::assertSame(10, $limited->rowCap);
        self::assertSame('SELECT display_name FROM employees LIMIT 0, 11', $limited->sql);

        $comment = $guard->prepare('SELECT display_name FROM employees /* DROP TABLE user_credentials */', $database);
        self::assertStringNotContainsString('DROP', $comment->sql);
        self::assertStringNotContainsString('user_credentials', $comment->sql);
        $trailing = $guard->prepare('SELECT display_name FROM employees; -- DROP TABLE employees', $database);
        self::assertStringNotContainsString('DROP', $trailing->sql);

        $literal = $guard->prepare("SELECT display_name FROM employees WHERE status = 'user_credentials'", $database);
        self::assertSame('select', $literal->kind);

        $cte = $guard->prepare('WITH c AS (SELECT display_name FROM employees) SELECT display_name FROM c', $database);
        self::assertSame('select', $cte->kind);
        self::assertStringContainsString('LIMIT 0, 1001', $cte->sql);

        $show = $guard->prepare('SHOW TABLES', $database);
        self::assertSame('show_tables', $show->kind);
        $columns = $guard->prepare('SHOW COLUMNS FROM employees', $database);
        self::assertSame('show_columns', $columns->kind);
        self::assertSame('employees', $columns->table);
        $describe = $guard->prepare('DESCRIBE employees', $database);
        self::assertSame('describe', $describe->kind);

        $this->assertRejected('DELETE FROM employees');
        $this->assertRejected('INSERT INTO employees (display_name) VALUES (\'a\')');
        $this->assertRejected('UPDATE employees SET display_name = \'a\'');
        $this->assertRejected('REPLACE INTO employees (display_name) VALUES (\'a\')');
        $this->assertRejected('DROP TABLE employees');
        $this->assertRejected('ALTER TABLE employees ADD COLUMN extra text');
        $this->assertRejected('CREATE TABLE extra (id integer)');
        $this->assertRejected('TRUNCATE TABLE employees');
        $this->assertRejected('GRANT SELECT ON employees TO explorer');
        $this->assertRejected('SET NAMES utf8');
        $this->assertRejected('CALL refresh_employees()');
        $this->assertRejected('SELECT display_name FROM employees; DROP TABLE employees');
        $this->assertRejected('SELECT password_hash FROM user_credentials');
        $this->assertRejected('SELECT password_hash AS name FROM employees');
        $this->assertRejected('SELECT HEX(password_hash) FROM employees');
        $this->assertRejected('SELECT CAST(password_hash AS CHAR) FROM employees');
        $literalValue = $guard->prepare("SELECT 'password_hash'", $database);
        self::assertSame('select', $literalValue->kind);
        self::assertStringNotContainsString('FROM', $literalValue->sql);
        $this->assertRejected('SELECT * FROM (SELECT password_hash FROM user_credentials) secret_rows');
        $this->assertRejected('SELECT display_name FROM employees UNION SELECT password_hash FROM user_credentials');
        $this->assertRejected('SELECT display_name FROM employees WHERE id IN (SELECT id FROM user_credentials)');
        $this->assertRejected('SELECT display_name FROM tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.employees');
        $this->assertRejected('SELECT display_name FROM information_schema.tables');
        $this->assertRejected('SELECT display_name INTO OUTFILE \'/tmp/employees.txt\' FROM employees');
        $this->assertRejected('SELECT display_name FROM employees FOR UPDATE');
        $this->assertRejected('SELECT SLEEP(1)');
        $this->assertRejected('SHOW DATABASES');
        $this->assertRejected('SHOW PROCESSLIST');
        $this->assertRejected('EXPLAIN DELETE FROM employees');
        $this->assertRejected('WITH c AS (SELECT display_name FROM employees) DELETE FROM employees');
        $this->assertRejected('DESCRIBE user_credentials');
    }

    public function testQueryTimeoutCategoryAndRateGate(): void
    {
        self::assertSame('timeout', ExplorerQueryRejectedException::categoryFor(new \RuntimeException('Maximum statement execution time exceeded')));
        $timeout = new ExplorerQueryRejectedException('The query took too long.', 'timeout', hash('sha256', 'slow'));
        self::assertSame('query_timeout', $timeout->problemCode());
        self::assertSame('The query took too long.', $timeout->getMessage());

        $gate = new ExplorerQueryGate(new ArrayAdapter());
        $first = $gate->acquire('actor-1');
        $second = $gate->acquire('actor-1');
        try {
            $gate->acquire('actor-1');
            self::fail('A third concurrent query was accepted.');
        } catch (TooManyAttemptsException $exception) {
            self::assertSame('rate_limited', $exception->problemCode());
            self::assertSame(429, $exception->getStatusCode());
        }
        $gate->release($first);
        $gate->release($second);
        $replacement = $gate->acquire('actor-1');
        $gate->release($replacement);

        $limited = new ExplorerQueryGate(new ArrayAdapter());
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $slot = $limited->acquire('actor-2');
            $limited->release($slot);
        }
        $this->expectException(TooManyAttemptsException::class);
        $limited->acquire('actor-2');
    }

    private function assertRejected(string $sql): void
    {
        try {
            (new ExplorerQueryGuard(new ExplorerPolicy()))->prepare($sql, 'tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
            self::fail('The query was accepted: '.$sql);
        } catch (ExplorerQueryRejectedException $exception) {
            self::assertSame('query_rejected', $exception->problemCode());
            self::assertSame(422, $exception->getStatusCode());
            self::assertSame('rejected', $exception->category());
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $exception->queryHash());
        }
    }
}
