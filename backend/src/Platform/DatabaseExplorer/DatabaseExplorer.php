<?php

declare(strict_types=1);

namespace App\Platform\DatabaseExplorer;

use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Platform\TenantRegistry\Domain\TenantInputValidator;
use App\Platform\TenantRegistry\Domain\TenantStatus;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantDatabaseConnection;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Result;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class DatabaseExplorer
{
    public function __construct(
        private readonly ExplorerConnections $connections,
        private readonly ExplorerPolicy $policy,
        private readonly ExplorerQueryGuard $queries,
        private readonly TenantRegistryStore $tenants,
        private readonly TenantInputValidator $validator,
    ) {
    }

    /**
     * @return list<array<string, string|null>>
     */
    public function databases(): array
    {
        $this->connections->assertConfigured();
        $platform = $this->connections->openPlatform();
        $platform->close();
        $rows = [[
            'target' => 'platform',
            'label' => 'Platform',
            'kind' => 'platform',
            'status' => null,
        ]];
        foreach ([TenantStatus::Active, TenantStatus::Suspended] as $status) {
            $offset = 0;
            do {
                $page = $this->tenants->searchTenants($status, '', 'slug', true, 100, $offset);
                foreach ($page['tenants'] as $tenant) {
                    if ($tenant->slug() === 'platform' || $this->connectionFor($tenant) === null) {
                        continue;
                    }
                    $rows[] = [
                        'target' => $tenant->slug(),
                        'label' => $tenant->legalName(),
                        'kind' => 'tenant',
                        'status' => $tenant->status()->value,
                    ];
                }
                $offset += 100;
            } while ($offset < $page['total']);
        }

        return $rows;
    }

    /**
     * @return array{targetId: string, tenantId: string|null, label: string}
     */
    public function target(string $target): array
    {
        $resolved = $this->resolve($target);
        if ($resolved['kind'] === 'platform') {
            return ['targetId' => 'platform', 'tenantId' => null, 'label' => 'platform'];
        }

        return [
            'targetId' => $resolved['tenantId'],
            'tenantId' => $resolved['tenantId'],
            'label' => $resolved['slug'],
        ];
    }

    /**
     * @return list<array{name: string, kind: string, rowEstimate: int|null}>
     */
    public function tables(string $target): array
    {
        $connection = $this->open($target);
        try {
            $tables = [];
            foreach ($this->tableInventory($connection) as $table) {
                if (!$this->policy->tableVisible($table['name'])) {
                    continue;
                }
                $tables[] = $table;
            }

            return $tables;
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new UnprocessableEntityHttpException('The table could not be read.');
        } finally {
            $connection->close();
        }
    }

    /**
     * @return array{name: string, kind: string, rowEstimate: int|null, columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>, foreignKeys: list<array<string, mixed>>}
     */
    public function table(string $target, string $table): array
    {
        $connection = $this->open($target);
        try {
            $found = $this->requireTable($connection, $table);
            $columns = $this->columns($connection, $table);
            $visible = array_values(array_filter(
                $columns,
                fn (array $column): bool => $this->policy->columnVisible($column['name']),
            ));

            return [
                'name' => $found['name'],
                'kind' => $found['kind'],
                'rowEstimate' => $found['rowEstimate'],
                'columns' => $visible,
                'indexes' => $this->indexes($connection, $table, $visible),
                'foreignKeys' => $this->foreignKeys($connection, $table),
            ];
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new UnprocessableEntityHttpException('The table could not be read.');
        } finally {
            $connection->close();
        }
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, limit: int, offset: int, returned: int, hasMore: bool}
     */
    public function rows(string $target, string $table, int $limit, int $offset, ?string $sort, string $direction, ?string $search, array $filters, ?string $columns): array
    {
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new UnprocessableEntityHttpException('The sort direction is invalid.');
        }
        if ($search !== null && strlen($search) > 100) {
            throw new UnprocessableEntityHttpException('The search is invalid.');
        }
        if (count($filters) > 10) {
            throw new UnprocessableEntityHttpException('The filter is invalid.');
        }
        $connection = $this->open($target);
        try {
            $this->requireTable($connection, $table);
            $available = $this->columns($connection, $table);
            $visible = [];
            foreach ($available as $column) {
                if ($this->policy->columnVisible($column['name'])) {
                    $visible[$column['name']] = $column;
                }
            }
            $selected = $this->selectedColumns($visible, $columns);
            $sortColumn = $this->sortColumn($visible, $selected, $sort);
            $where = [];
            $parameters = [];
            foreach ($filters as $name => $value) {
                if (!isset($visible[$name]) || !is_string($value) || strlen($value) > 200) {
                    throw new UnprocessableEntityHttpException('The filter is invalid.');
                }
                $where[] = $connection->quoteSingleIdentifier($name).' = ?';
                $parameters[] = $value;
            }
            if ($search !== null && $search !== '') {
                $text = [];
                foreach ($selected as $name) {
                    if ($this->isText($visible[$name]['type'])) {
                        $text[] = $connection->quoteSingleIdentifier($name).' LIKE ? ESCAPE \'\\\'';
                        $parameters[] = '%'.$this->like($search).'%';
                    }
                }
                if ($text === []) {
                    throw new UnprocessableEntityHttpException('This table has no searchable columns.');
                }
                $where[] = '('.implode(' OR ', $text).')';
            }
            $list = [];
            foreach ($selected as $name) {
                $list[] = $connection->quoteSingleIdentifier($name);
            }
            $sql = 'SELECT '.implode(', ', $list).' FROM '.$connection->quoteSingleIdentifier($table);
            if ($where !== []) {
                $sql .= ' WHERE '.implode(' AND ', $where);
            }
            $sql .= ' ORDER BY '.$connection->quoteSingleIdentifier($sortColumn).' '.strtoupper($direction);
            $sql .= ' LIMIT '.($limit + 1).' OFFSET '.$offset;
            $fetched = $connection->fetchAllAssociative($sql, $parameters);
            $hasMore = count($fetched) > $limit;
            $page = array_slice($fetched, 0, $limit);
            $rows = [];
            foreach ($page as $row) {
                $formatted = [];
                foreach ($selected as $name) {
                    $formatted[$name] = $this->shorten($this->format($visible[$name]['type'], $row[$name] ?? null));
                }
                $rows[] = $formatted;
            }

            return [
                'columns' => $selected,
                'rows' => $rows,
                'limit' => $limit,
                'offset' => $offset,
                'returned' => count($rows),
                'hasMore' => $hasMore,
            ];
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new UnprocessableEntityHttpException('The table could not be read.');
        } finally {
            $connection->close();
        }
    }

    /**
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, limit: int, offset: int, returned: int, hasMore: bool, queryHash: string, statement: string}
     */
    public function query(string $target, string $sql): array
    {
        $connection = $this->open($target);
        $prepared = null;
        $result = null;
        try {
            $prepared = $this->queries->prepare($sql, (string) $connection->getDatabase());
            if ($prepared->kind === 'show_tables') {
                return $this->catalogRows($prepared, ['name', 'kind'], $this->visibleTables($connection));
            }
            if ($prepared->kind === 'show_columns' || $prepared->kind === 'describe') {
                return $this->catalogRows($prepared, ['name', 'type', 'nullable', 'primaryKey'], $this->visibleColumns($connection, $prepared));
            }
            $result = $connection->executeQuery($prepared->sql);

            return $this->collect($prepared, $result);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $error) {
            $category = ExplorerQueryRejectedException::categoryFor($error);
            $message = $category === 'timeout' ? 'The query took too long.' : 'The query could not be run.';

            throw new ExplorerQueryRejectedException($message, $category, $prepared?->queryHash);
        } finally {
            if ($result instanceof Result) {
                $result->free();
            }
            $connection->close();
        }
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, limit: int, offset: int, returned: int, hasMore: bool, queryHash: string, statement: string}
     */
    private function catalogRows(PreparedExplorerQuery $prepared, array $columns, array $rows): array
    {
        $kept = [];
        $bytes = 0;
        $truncated = false;
        foreach ($rows as $row) {
            if (count($kept) >= $prepared->rowCap) {
                $truncated = true;
                break;
            }
            $formatted = [];
            $rowBytes = 0;
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $cell = is_string($value) ? $this->shorten($value) : (is_bool($value) || is_int($value) || is_float($value) || $value === null ? $value : null);
                $formatted[$column] = $cell;
                $rowBytes += is_string($cell) ? strlen($cell) : 8;
            }
            if ($bytes + $rowBytes > 512000) {
                $truncated = true;
                break;
            }
            $bytes += $rowBytes;
            $kept[] = $formatted;
        }

        return $this->queryPage($prepared, $columns, $kept, $truncated);
    }

    /**
     * @return list<array{name: string, kind: string}>
     */
    private function visibleTables(Connection $connection): array
    {
        $rows = [];
        foreach ($this->tableInventory($connection) as $table) {
            if (!$this->policy->tableVisible($table['name'])) {
                continue;
            }
            $rows[] = ['name' => $table['name'], 'kind' => $table['kind']];
        }

        return $rows;
    }

    /**
     * @return list<array{name: string, type: string, nullable: bool, primaryKey: bool}>
     */
    private function visibleColumns(Connection $connection, PreparedExplorerQuery $prepared): array
    {
        $table = (string) $prepared->table;
        $found = null;
        foreach ($this->tableInventory($connection) as $candidate) {
            if (strcasecmp($candidate['name'], $table) === 0) {
                $found = $candidate['name'];
                break;
            }
        }
        if ($found === null) {
            throw new ExplorerQueryRejectedException('The query could not be run.', 'execution', $prepared->queryHash);
        }
        $rows = [];
        foreach ($this->columns($connection, $found) as $column) {
            if (!$this->policy->columnVisible($column['name'])) {
                continue;
            }
            if ($prepared->column !== null && strcasecmp($prepared->column, $column['name']) !== 0) {
                continue;
            }
            $rows[] = $column;
        }

        return $rows;
    }

    /**
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, limit: int, offset: int, returned: int, hasMore: bool, queryHash: string, statement: string}
     */
    private function collect(PreparedExplorerQuery $prepared, Result $result): array
    {
        $columns = [];
        $rows = [];
        $bytes = 0;
        $truncated = false;
        $seen = 0;
        while (($row = $result->fetchAssociative()) !== false) {
            ++$seen;
            if ($seen > $prepared->rowCap) {
                $truncated = true;
                break;
            }
            $formatted = [];
            $rowBytes = 0;
            foreach ($row as $name => $value) {
                $column = (string) $name;
                if (!$this->policy->columnVisible($column)) {
                    continue;
                }
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
                $cell = $this->shorten($this->format('', $value));
                $formatted[$column] = $cell;
                $rowBytes += is_string($cell) ? strlen($cell) : 8;
            }
            if ($bytes + $rowBytes > 512000) {
                $truncated = true;
                break;
            }
            $bytes += $rowBytes;
            $rows[] = $formatted;
        }

        return $this->queryPage($prepared, $columns, $rows, $truncated);
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, limit: int, offset: int, returned: int, hasMore: bool, queryHash: string, statement: string}
     */
    private function queryPage(PreparedExplorerQuery $prepared, array $columns, array $rows, bool $truncated): array
    {
        return [
            'columns' => $columns,
            'rows' => $rows,
            'limit' => $prepared->rowCap,
            'offset' => 0,
            'returned' => count($rows),
            'hasMore' => $truncated,
            'queryHash' => $prepared->queryHash,
            'statement' => $prepared->kind,
        ];
    }

    private function shorten(string|int|float|bool|null $value): string|int|float|bool|null
    {
        if (!is_string($value) || strlen($value) <= 400) {
            return $value;
        }

        return substr($value, 0, 400);
    }

    private function open(string $target): Connection
    {
        $resolved = $this->resolve($target);
        if ($resolved['kind'] === 'platform') {
            return $this->connections->openPlatform();
        }
        try {
            return $this->connections->openTenant($resolved['databaseName'], $resolved['host'], $resolved['driver']);
        } catch (ExplorerTargetMissingException) {
            throw new NotFoundHttpException('Unknown database.');
        }
    }

    /**
     * @return array{kind: 'platform'}|array{kind: 'tenant', databaseName: string, host: string, driver: string, tenantId: string, slug: string}
     */
    private function resolve(string $target): array
    {
        $this->connections->assertConfigured();
        if ($target === 'platform') {
            return ['kind' => 'platform'];
        }
        try {
            $slug = $this->validator->slug($target);
        } catch (InvalidTenantInputException $exception) {
            throw new UnprocessableEntityHttpException('The database target is invalid.', $exception);
        }
        $tenant = $this->tenants->findTenantBySlug($slug);
        $stored = $tenant instanceof Tenant ? $this->connectionFor($tenant) : null;
        if (!$tenant instanceof Tenant || !$stored instanceof TenantDatabaseConnection) {
            throw new NotFoundHttpException('Unknown database.');
        }

        return [
            'kind' => 'tenant',
            'databaseName' => $stored->databaseName(),
            'host' => $stored->host(),
            'driver' => $stored->driver(),
            'tenantId' => $tenant->id(),
            'slug' => $tenant->slug(),
        ];
    }

    private function connectionFor(Tenant $tenant): ?TenantDatabaseConnection
    {
        if ($tenant->status() !== TenantStatus::Active && $tenant->status() !== TenantStatus::Suspended) {
            return null;
        }
        $stored = $this->tenants->findConnection($tenant->id());
        if (!$stored instanceof TenantDatabaseConnection || $stored->credentialsState() !== 'present') {
            return null;
        }
        if ($stored->driver() !== 'sqlite' && $stored->driver() !== 'mysql') {
            return null;
        }
        if (preg_match('/^tenant_[a-f0-9]{32}$/', $stored->databaseName()) !== 1) {
            return null;
        }

        return $stored;
    }

    /**
     * @return array{name: string, kind: string, rowEstimate: int|null}
     */
    private function requireTable(Connection $connection, string $table): array
    {
        if (!$this->policy->tableVisible($table)) {
            throw new NotFoundHttpException('Unknown table.');
        }
        foreach ($this->tableInventory($connection) as $candidate) {
            if ($candidate['name'] === $table) {
                return $candidate;
            }
        }

        throw new NotFoundHttpException('Unknown table.');
    }

    /**
     * @return list<array{name: string, kind: string, rowEstimate: int|null}>
     */
    private function tableInventory(Connection $connection): array
    {
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $rows = $connection->fetchAllAssociative(
                "SELECT name, type FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%' ORDER BY name",
            );
            $tables = [];
            foreach ($rows as $row) {
                $name = (string) ($row['name'] ?? '');
                if (!ExplorerPolicy::identifier($name)) {
                    continue;
                }
                $tables[] = [
                    'name' => $name,
                    'kind' => ($row['type'] ?? '') === 'view' ? 'view' : 'table',
                    'rowEstimate' => null,
                ];
            }

            return $tables;
        }
        $schema = $connection->getDatabase();
        $rows = $connection->fetchAllAssociative(
            "SELECT TABLE_NAME AS name, TABLE_TYPE AS kind, TABLE_ROWS AS row_estimate FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE IN ('BASE TABLE', 'VIEW') ORDER BY TABLE_NAME",
            [$schema],
        );
        $tables = [];
        foreach ($rows as $row) {
            $normalized = $this->lower($row);
            $name = (string) ($normalized['name'] ?? '');
            if (!ExplorerPolicy::identifier($name)) {
                continue;
            }
            $estimate = $normalized['row_estimate'] ?? null;
            $tables[] = [
                'name' => $name,
                'kind' => ($normalized['kind'] ?? '') === 'VIEW' ? 'view' : 'table',
                'rowEstimate' => ($normalized['kind'] ?? '') === 'VIEW' || !is_numeric($estimate) ? null : (int) $estimate,
            ];
        }

        return $tables;
    }

    /**
     * @return list<array{name: string, type: string, nullable: bool, primaryKey: bool}>
     */
    private function columns(Connection $connection, string $table): array
    {
        $quoted = $connection->quoteSingleIdentifier($table);
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $rows = $connection->fetchAllAssociative('PRAGMA table_info('.$quoted.')');
            $columns = [];
            foreach ($rows as $row) {
                $normalized = $this->lower($row);
                $name = (string) ($normalized['name'] ?? '');
                if (!ExplorerPolicy::identifier($name)) {
                    continue;
                }
                $columns[] = [
                    'name' => $name,
                    'type' => (string) ($normalized['type'] ?? ''),
                    'nullable' => (int) ($normalized['notnull'] ?? 0) === 0,
                    'primaryKey' => (int) ($normalized['pk'] ?? 0) > 0,
                ];
            }

            return $columns;
        }
        $rows = $connection->fetchAllAssociative(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_KEY AS column_key FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$connection->getDatabase(), $table],
        );
        $columns = [];
        foreach ($rows as $row) {
            $normalized = $this->lower($row);
            $name = (string) ($normalized['name'] ?? '');
            if (!ExplorerPolicy::identifier($name)) {
                continue;
            }
            $columns[] = [
                'name' => $name,
                'type' => (string) ($normalized['type'] ?? ''),
                'nullable' => ($normalized['nullable'] ?? '') === 'YES',
                'primaryKey' => ($normalized['column_key'] ?? '') === 'PRI',
            ];
        }

        return $columns;
    }

    /**
     * @param list<array{name: string, type: string, nullable: bool, primaryKey: bool}> $visible
     *
     * @return list<array{name: string, unique: bool, columns: list<string>}>
     */
    private function indexes(Connection $connection, string $table, array $visible): array
    {
        $names = array_column($visible, 'name');
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $quoted = $connection->quoteSingleIdentifier($table);
            $listed = $connection->fetchAllAssociative('PRAGMA index_list('.$quoted.')');
            $indexes = [];
            foreach ($listed as $index) {
                $normalized = $this->lower($index);
                $indexName = (string) ($normalized['name'] ?? '');
                if (!ExplorerPolicy::identifier($indexName)) {
                    continue;
                }
                $info = $connection->fetchAllAssociative('PRAGMA index_info('.$connection->quoteSingleIdentifier($indexName).')');
                $columns = [];
                foreach ($info as $column) {
                    $columnName = (string) ($this->lower($column)['name'] ?? '');
                    if (in_array($columnName, $names, true)) {
                        $columns[] = $columnName;
                    }
                }
                if ($columns === []) {
                    continue;
                }
                $indexes[] = [
                    'name' => $indexName,
                    'unique' => (int) ($normalized['unique'] ?? 0) === 1,
                    'columns' => $columns,
                ];
            }

            return $indexes;
        }
        $rows = $connection->fetchAllAssociative(
            'SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, COLUMN_NAME AS column_name, SEQ_IN_INDEX AS position FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$connection->getDatabase(), $table],
        );
        $grouped = [];
        foreach ($rows as $row) {
            $normalized = $this->lower($row);
            $indexName = (string) ($normalized['name'] ?? '');
            $columnName = (string) ($normalized['column_name'] ?? '');
            if (!ExplorerPolicy::identifier($indexName) || !in_array($columnName, $names, true)) {
                continue;
            }
            if (!isset($grouped[$indexName])) {
                $grouped[$indexName] = [
                    'unique' => (int) ($normalized['non_unique'] ?? 1) === 0,
                    'columns' => [],
                ];
            }
            $grouped[$indexName]['columns'][] = $columnName;
        }
        $indexes = [];
        foreach ($grouped as $name => $index) {
            $indexes[] = ['name' => $name, 'unique' => $index['unique'], 'columns' => $index['columns']];
        }

        return $indexes;
    }

    /**
     * @return list<array{name: string, columns: list<string>, referencedTable: string, referencedColumns: list<string>}>
     */
    private function foreignKeys(Connection $connection, string $table): array
    {
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $rows = $connection->fetchAllAssociative('PRAGMA foreign_key_list('.$connection->quoteSingleIdentifier($table).')');
            $grouped = [];
            foreach ($rows as $row) {
                $normalized = $this->lower($row);
                $referenced = (string) ($normalized['table'] ?? '');
                $from = (string) ($normalized['from'] ?? '');
                $to = (string) ($normalized['to'] ?? '');
                if (!$this->policy->tableVisible($referenced) || !$this->policy->columnVisible($from) || !$this->policy->columnVisible($to)) {
                    continue;
                }
                $id = (string) ($normalized['id'] ?? '0');
                if (!isset($grouped[$id])) {
                    $grouped[$id] = ['table' => $referenced, 'columns' => [], 'referenced' => []];
                }
                $grouped[$id]['table'] = $referenced;
                $grouped[$id]['columns'][] = $from;
                $grouped[$id]['referenced'][] = $to;
            }
            $keys = [];
            foreach ($grouped as $id => $key) {
                $keys[] = [
                    'name' => 'fk_'.$id,
                    'columns' => $key['columns'],
                    'referencedTable' => $key['table'],
                    'referencedColumns' => $key['referenced'],
                ];
            }

            return $keys;
        }
        $rows = $connection->fetchAllAssociative(
            'SELECT CONSTRAINT_NAME AS name, COLUMN_NAME AS column_name, REFERENCED_TABLE_NAME AS referenced_table, REFERENCED_COLUMN_NAME AS referenced_column, ORDINAL_POSITION AS position FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION',
            [$connection->getDatabase(), $table],
        );
        $grouped = [];
        foreach ($rows as $row) {
            $normalized = $this->lower($row);
            $name = (string) ($normalized['name'] ?? '');
            $referenced = (string) ($normalized['referenced_table'] ?? '');
            $from = (string) ($normalized['column_name'] ?? '');
            $to = (string) ($normalized['referenced_column'] ?? '');
            if (!ExplorerPolicy::identifier($name) || !$this->policy->tableVisible($referenced) || !$this->policy->columnVisible($from) || !$this->policy->columnVisible($to)) {
                continue;
            }
            if (!isset($grouped[$name])) {
                $grouped[$name] = ['table' => $referenced, 'columns' => [], 'referenced' => []];
            }
            $grouped[$name]['table'] = $referenced;
            $grouped[$name]['columns'][] = $from;
            $grouped[$name]['referenced'][] = $to;
        }
        $keys = [];
        foreach ($grouped as $name => $key) {
            $keys[] = [
                'name' => $name,
                'columns' => $key['columns'],
                'referencedTable' => $key['table'],
                'referencedColumns' => $key['referenced'],
            ];
        }

        return $keys;
    }

    /**
     * @param array<string, array{name: string, type: string, nullable: bool, primaryKey: bool}> $visible
     *
     * @return list<string>
     */
    private function selectedColumns(array $visible, ?string $requested): array
    {
        if ($visible === []) {
            throw new NotFoundHttpException('Unknown table.');
        }
        if ($requested === null || $requested === '') {
            return array_keys($visible);
        }
        $names = [];
        foreach (explode(',', $requested) as $name) {
            $name = trim($name);
            if (!isset($visible[$name])) {
                throw new UnprocessableEntityHttpException('The column is not available.');
            }
            $names[$name] = $name;
        }

        return array_values($names);
    }

    /**
     * @param array<string, array{name: string, type: string, nullable: bool, primaryKey: bool}> $visible
     * @param list<string> $selected
     */
    private function sortColumn(array $visible, array $selected, ?string $sort): string
    {
        if ($sort !== null && $sort !== '') {
            if (!isset($visible[$sort])) {
                throw new UnprocessableEntityHttpException('The sort column is not available.');
            }

            return $sort;
        }
        foreach ($visible as $column) {
            if ($column['primaryKey']) {
                return $column['name'];
            }
        }

        return $selected[0];
    }

    private function isText(string $type): bool
    {
        return preg_match('/blob|binary/i', $type) !== 1 && preg_match('/char|text|clob|string/i', $type) === 1;
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function format(string $type, mixed $value): string|int|float|bool|null
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/blob|binary/i', $type) === 1 || is_resource($value)) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/int/i', $type) === 1 && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed> $row
     *
     * @return array<string, mixed>
     */
    private function lower(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[strtolower((string) $key)] = $value;
        }

        return $normalized;
    }
}
