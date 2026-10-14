<?php

declare(strict_types=1);

namespace App\Platform\DatabaseExplorer;

use App\Identity\Domain\Permission;
use App\Identity\Infrastructure\Security\AccessGate;
use App\Platform\TenantRegistry\Application\PlatformAuditRecorder;
use App\Identity\Infrastructure\Http\JsonBody;
use App\Security\TooManyAttemptsException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\PageQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DatabaseExplorerController
{
    public function __construct(
        private readonly AccessGate $access,
        private readonly DatabaseExplorer $explorer,
        private readonly ExplorerQueryGate $queries,
        private readonly PlatformAuditRecorder $audit,
    ) {
    }

    #[Route('/api/v1/platform/databases', name: 'platform_databases', methods: ['GET'])]
    public function databases(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::PlatformDatabaseExplore);
        $started = hrtime(true);
        try {
            $this->rejectQueryKeys($request);
            $rows = $this->explorer->databases();
            $duration = $this->duration($started);
            $this->audit->record('database.catalog_viewed', 'database', 'platform', $actor->id(), [
                'label' => 'platform',
                'durationMs' => $duration,
                'rowCount' => count($rows),
                'result' => 'success',
            ]);

            return ApiResponse::ok($rows, ['durationMs' => $duration]);
        } catch (\Throwable $exception) {
            $this->audit->record('database.catalog_viewed', 'database', 'platform', $actor->id(), [
                'label' => 'platform',
                'durationMs' => $this->duration($started),
                'result' => 'failure',
            ]);
            throw $exception;
        }
    }

    #[Route('/api/v1/platform/databases/{target}/tables', name: 'platform_database_tables', methods: ['GET'])]
    public function tables(Request $request, string $target): JsonResponse
    {
        $actor = $this->access->require(Permission::PlatformDatabaseExplore);

        return $this->respond($request, $actor->id(), 'database.tables_viewed', $target, function () use ($target): array {
            $tables = $this->explorer->tables($target);

            return ['data' => $tables, 'rowCount' => count($tables)];
        });
    }

    #[Route('/api/v1/platform/databases/{target}/tables/{table}', name: 'platform_database_table', methods: ['GET'])]
    public function table(Request $request, string $target, string $table): JsonResponse
    {
        $actor = $this->access->require(Permission::PlatformDatabaseExplore);

        return $this->respond($request, $actor->id(), 'database.table_viewed', $target, function () use ($target, $table): array {
            return ['data' => $this->explorer->table($target, $table), 'rowCount' => null, 'table' => $table];
        });
    }

    #[Route('/api/v1/platform/databases/{target}/tables/{table}/rows', name: 'platform_database_rows', methods: ['GET'])]
    public function rows(Request $request, string $target, string $table): JsonResponse
    {
        $actor = $this->access->require(Permission::PlatformDatabaseExplore);

        return $this->respond($request, $actor->id(), 'database.rows_viewed', $target, function () use ($request, $target, $table): array {
            $limit = PageQuery::limit($request, 50);
            $offset = PageQuery::offset($request);
            $page = $this->explorer->rows(
                $target,
                $table,
                $limit,
                $offset,
                $this->text($request, 'sort'),
                $this->text($request, 'direction') ?? 'asc',
                $this->text($request, 'q'),
                $this->filters($request),
                $this->text($request, 'columns'),
            );

            return [
                'data' => ['columns' => $page['columns'], 'rows' => $page['rows']],
                'rowCount' => $page['returned'],
                'table' => $table,
                'page' => [
                    'limit' => $page['limit'],
                    'offset' => $page['offset'],
                    'returned' => $page['returned'],
                    'hasMore' => $page['hasMore'],
                ],
            ];
        });
    }

    #[Route('/api/v1/platform/databases/{target}/query', name: 'platform_database_query', methods: ['POST'])]
    public function query(Request $request, string $target): JsonResponse
    {
        $actor = $this->access->require(Permission::PlatformDatabaseExplore);

        return $this->respond($request, $actor->id(), 'database.query_executed', $target, function () use ($request, $target, $actor): array {
            $slot = $this->queries->acquire($actor->id());
            try {
                if (strlen($request->getContent()) > 8000) {
                    throw new ExplorerQueryRejectedException();
                }
                $body = JsonBody::read($request);
                foreach (array_keys($body) as $key) {
                    if (!in_array(strtolower((string) $key), ['sql', 'limit', 'offset'], true)) {
                        throw new UnprocessableEntityHttpException('The request includes a field the platform does not accept.');
                    }
                }
                $page = $this->explorer->query($target, JsonBody::string($body, 'sql'));

                return [
                    'data' => ['columns' => $page['columns'], 'rows' => $page['rows']],
                    'rowCount' => $page['returned'],
                    'queryHash' => $page['queryHash'],
                    'statement' => $page['statement'],
                    'page' => [
                        'limit' => $page['limit'],
                        'offset' => $page['offset'],
                        'returned' => $page['returned'],
                        'hasMore' => $page['hasMore'],
                        'truncated' => $page['hasMore'],
                    ],
                ];
            } finally {
                $this->queries->release($slot);
            }
        });
    }

    /**
     * @param callable(): array{data: mixed, rowCount: int|null, table?: string, queryHash?: string, statement?: string, page?: array<string, int|bool>} $read
     */
    private function respond(Request $request, string $actorId, string $action, string $target, callable $read): JsonResponse
    {
        $started = hrtime(true);
        $targetId = 'unknown';
        $tenantId = null;
        $label = null;
        try {
            $this->rejectQueryKeys($request);
            $located = $this->explorer->target($target);
            $targetId = $located['targetId'];
            $tenantId = $located['tenantId'];
            $label = $located['label'];
            $result = $read();
            $duration = $this->duration($started);
            $this->audit->record($action, 'database', $targetId, $actorId, $this->metadata($label, $duration, 'success', $tenantId, $result['table'] ?? null, $result['rowCount'], [
                'queryHash' => $result['queryHash'] ?? null,
                'statement' => $result['statement'] ?? null,
            ]));
            $meta = ['durationMs' => $duration];
            if (isset($result['page'])) {
                $result['page']['durationMs'] = $duration;
                $meta['page'] = $result['page'];
            }

            return ApiResponse::ok($result['data'], $meta);
        } catch (\Throwable $exception) {
            $hash = $exception instanceof ExplorerQueryRejectedException ? $exception->queryHash() : null;
            $this->audit->record($action, 'database', $targetId, $actorId, $this->metadata($label, $this->duration($started), 'failure', $tenantId, null, null, [
                'queryHash' => $hash,
                'errorCategory' => $this->errorCategory($exception),
            ]));
            throw $exception;
        }
    }

    /**
     * @param array<string, string|null> $extra
     *
     * @return array<string, scalar|null>
     */
    private function metadata(?string $label, int $duration, string $result, ?string $tenantId, ?string $table, ?int $rowCount, array $extra = []): array
    {
        $metadata = [
            'durationMs' => $duration,
            'result' => $result,
        ];
        if ($label !== null) {
            $metadata['label'] = $label;
        }
        if ($tenantId !== null) {
            $metadata['tenantId'] = $tenantId;
        }
        if ($table !== null && ExplorerPolicy::identifier($table)) {
            $metadata['table'] = $table;
        }
        if ($rowCount !== null) {
            $metadata['rowCount'] = $rowCount;
        }
        $queryHash = $extra['queryHash'] ?? null;
        if (is_string($queryHash) && preg_match('/^[a-f0-9]{64}$/', $queryHash) === 1) {
            $metadata['queryHash'] = $queryHash;
        }
        $statement = $extra['statement'] ?? null;
        if (is_string($statement) && in_array($statement, ['select', 'show_tables', 'show_columns', 'describe', 'explain'], true)) {
            $metadata['statement'] = $statement;
        }
        $errorCategory = $extra['errorCategory'] ?? null;
        if (is_string($errorCategory) && in_array($errorCategory, ['rejected', 'timeout', 'execution', 'unavailable', 'rate_limited', 'not_found'], true)) {
            $metadata['errorCategory'] = $errorCategory;
        }

        return $metadata;
    }

    private function errorCategory(\Throwable $exception): string
    {
        if ($exception instanceof ExplorerQueryRejectedException) {
            return $exception->category();
        }
        if ($exception instanceof TooManyAttemptsException) {
            return 'rate_limited';
        }
        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $exception->getStatusCode() === 404) {
            return 'not_found';
        }
        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $exception->getStatusCode() === 503) {
            return 'unavailable';
        }

        return 'execution';
    }

    private function rejectQueryKeys(Request $request): void
    {
        foreach (array_keys($request->query->all()) as $key) {
            if (in_array(strtolower((string) $key), [
                'database',
                'databasename',
                'dbname',
                'host',
                'port',
                'username',
                'password',
                'connection',
                'tenantid',
                'actorid',
                'sql',
                'query',
            ], true)) {
                throw new UnprocessableEntityHttpException('The request includes a field the platform does not accept.');
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        if (!$request->query->has('filter')) {
            return [];
        }
        $all = $request->query->all();
        $filter = $all['filter'] ?? null;
        if (!is_array($filter) || count($filter) > 10) {
            throw new UnprocessableEntityHttpException('The filter is invalid.');
        }
        $values = [];
        foreach ($filter as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new UnprocessableEntityHttpException('The filter is invalid.');
            }
            $values[$name] = $value;
        }

        return $values;
    }

    private function text(Request $request, string $name): ?string
    {
        if (!$request->query->has($name)) {
            return null;
        }
        $all = $request->query->all();
        $value = $all[$name] ?? null;
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException('The page request is invalid.');
        }

        return $value;
    }

    private function duration(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
