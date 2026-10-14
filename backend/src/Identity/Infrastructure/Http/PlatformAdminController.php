<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\AccountInput;
use App\Identity\Domain\Permission;
use App\Identity\Infrastructure\Security\AccessGate;
use App\Platform\TenantRegistry\Application\PlatformTenantAdministration;
use App\Platform\TenantRegistry\Application\TenantCatalog;
use App\Platform\TenantRegistry\Application\TenantQuotaService;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Platform\TenantRegistry\Domain\TenantLifecycleException;
use App\Platform\TenantRegistry\Domain\TenantProvisioningException;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\PageQuery;
use App\TenantContext\Domain\TenantNotProvisionedException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformAdminController
{
    public function __construct(
        private readonly AccessGate $access,
        private readonly TenantCatalog $catalog,
        private readonly PlatformTenantAdministration $administration,
        private readonly TenantRegistryStore $store,
        private readonly AccountInput $accounts,
        private readonly TenantQuotaService $quotas,
    ) {
    }

    #[Route('/api/v1/platform/tenants', name: 'platform_tenants', methods: ['GET'])]
    public function tenants(Request $request): JsonResponse
    {
        $this->access->require(Permission::TenantsManage);
        $limit = PageQuery::limit($request, 50);
        $offset = PageQuery::offset($request);
        /** @var array{rows: list<array<string, mixed>>, total: int} $listed */
        $listed = $this->run(fn (): array => $this->catalog->list(
            $request->query->get('status'),
            $request->query->get('q'),
            $request->query->get('sort'),
            $request->query->get('direction'),
            $limit,
            $offset,
        ));

        return $this->page($listed, $limit, $offset);
    }

    #[Route('/api/v1/platform/tenants/{slug}', name: 'platform_tenant_show', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        $this->access->require(Permission::TenantsManage);

        return ApiResponse::ok($this->run(fn (): array => $this->administration->show($slug)));
    }

    #[Route('/api/v1/platform/tenants', name: 'platform_tenants_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::TenantLifecycleManage);
        $body = $this->body($request);
        $this->rejectConnectionFields($body);
        $this->rejectPlan($body);
        $administrator = $this->administrator($body);

        return ApiResponse::ok($this->run(fn (): array => $this->administration->create(
            JsonBody::string($body, 'legalName'),
            JsonBody::string($body, 'slug'),
            JsonBody::optionalString($body, 'hostname'),
            JsonBody::string($body, 'timezone'),
            JsonBody::string($body, 'defaultLocale'),
            array_key_exists('locales', $body) ? JsonBody::stringList($body, 'locales') : [],
            $administrator,
            $actor->id(),
        )), [], 201);
    }

    #[Route('/api/v1/platform/tenants/{slug}', name: 'platform_tenant_update', methods: ['PATCH'])]
    public function update(Request $request, string $slug): JsonResponse
    {
        $actor = $this->access->require(Permission::TenantLifecycleManage);
        $body = $this->body($request);
        $this->rejectConnectionFields($body);
        $this->rejectPlan($body);

        return ApiResponse::ok($this->run(fn (): array => $this->administration->update($slug, $body, $actor->id())));
    }

    #[Route('/api/v1/platform/tenants/{slug}/quotas', name: 'platform_tenant_quotas', methods: ['GET'])]
    #[Route('/api/v1/platform/tenants/{slug}/usage', name: 'platform_tenant_usage', methods: ['GET'])]
    public function quotas(string $slug): JsonResponse
    {
        $this->access->require(Permission::TenantsManage);

        return ApiResponse::ok($this->run(fn (): array => $this->quotas->platformReport($this->administration->showTenant($slug))));
    }

    #[Route('/api/v1/platform/tenants/{slug}/quotas', name: 'platform_tenant_quotas_update', methods: ['PATCH'])]
    public function updateQuotas(Request $request, string $slug): JsonResponse
    {
        $actor = $this->access->require(Permission::TenantLifecycleManage);
        $body = $this->body($request);
        $this->rejectConnectionFields($body);
        $this->rejectPlan($body);
        foreach (array_keys($body) as $key) {
            if (!in_array($key, ['maxUsers', 'maxEmployees', 'maxActiveDevices'], true)) {
                throw new UnprocessableEntityHttpException('The request includes a field the platform does not accept.');
            }
        }
        foreach (['maxUsers', 'maxEmployees', 'maxActiveDevices'] as $key) {
            if (!array_key_exists($key, $body)) {
                throw new UnprocessableEntityHttpException('A quota must be a non-negative integer or null.');
            }
        }

        return ApiResponse::ok($this->run(fn (): array => $this->quotas->replace(
            $this->administration->showTenant($slug),
            [
                'maxUsers' => $this->quotas->limitValue($body['maxUsers']),
                'maxEmployees' => $this->quotas->limitValue($body['maxEmployees']),
                'maxActiveDevices' => $this->quotas->limitValue($body['maxActiveDevices']),
            ],
            $actor->id(),
        )));
    }

    #[Route('/api/v1/platform/tenants/{slug}/activate', name: 'platform_tenant_activate', methods: ['POST'])]
    public function activate(string $slug): JsonResponse
    {
        $actor = $this->access->require(Permission::TenantLifecycleManage);

        return ApiResponse::ok($this->run(fn (): array => $this->administration->activate($slug, $actor->id())));
    }

    #[Route('/api/v1/platform/tenants/{slug}/suspend', name: 'platform_tenant_suspend', methods: ['POST'])]
    public function suspend(string $slug): JsonResponse
    {
        $actor = $this->access->require(Permission::TenantLifecycleManage);

        return ApiResponse::ok($this->run(fn (): array => $this->administration->suspend($slug, $actor->id())));
    }

    #[Route('/api/v1/platform/tenants/{slug}/reactivate', name: 'platform_tenant_reactivate', methods: ['POST'])]
    public function reactivate(string $slug): JsonResponse
    {
        $actor = $this->access->require(Permission::TenantLifecycleManage);

        return ApiResponse::ok($this->run(fn (): array => $this->administration->reactivate($slug, $actor->id())));
    }

    #[Route('/api/v1/platform/tenants/{slug}/archive', name: 'platform_tenant_archive', methods: ['POST'])]
    public function archive(Request $request, string $slug): JsonResponse
    {
        $actor = $this->access->require(Permission::TenantLifecycleManage);
        $body = $this->body($request);

        return ApiResponse::ok($this->run(fn (): array => $this->administration->archive(
            $slug,
            JsonBody::string($body, 'confirmation'),
            $actor->id(),
        )));
    }

    #[Route('/api/v1/platform/tenants/{slug}/drop-database', name: 'platform_tenant_drop_database', methods: ['POST'])]
    public function dropDatabase(Request $request, string $slug): JsonResponse
    {
        $actor = $this->access->require(Permission::TenantLifecycleManage);
        $body = $this->body($request);

        return ApiResponse::ok($this->run(fn (): array => $this->administration->dropDatabase(
            $slug,
            JsonBody::string($body, 'confirmation'),
            $actor->id(),
        )));
    }

    #[Route('/api/v1/platform/tenants/{slug}/database/check', name: 'platform_tenant_database_check', methods: ['POST'])]
    public function check(string $slug): JsonResponse
    {
        $actor = $this->access->require(Permission::TenantLifecycleManage);

        return ApiResponse::ok($this->run(fn (): array => $this->administration->check($slug, $actor->id())));
    }

    #[Route('/api/v1/platform/tenants/{slug}/migrate', name: 'platform_tenant_migrate', methods: ['POST'])]
    public function migrate(string $slug): JsonResponse
    {
        $actor = $this->access->require(Permission::TenantLifecycleManage);

        return ApiResponse::ok($this->run(fn (): array => $this->administration->migrate($slug, $actor->id())));
    }

    #[Route('/api/v1/platform/audit', name: 'platform_audit', methods: ['GET'])]
    public function audit(Request $request): JsonResponse
    {
        $this->access->require(Permission::PlatformAdminister);
        $tenantId = null;
        $slug = $request->query->get('tenant');
        if (is_string($slug) && trim($slug) !== '') {
            $tenant = $this->store->findTenantBySlug(trim($slug));
            if ($tenant === null) {
                throw new NotFoundHttpException('Unknown tenant.');
            }
            $tenantId = $tenant->id();
        } elseif ($slug !== null && $slug !== '') {
            throw new UnprocessableEntityHttpException('The page request is invalid.');
        }

        return $this->page(
            $this->catalog->audits($tenantId, PageQuery::limit($request, 50), PageQuery::offset($request)),
            PageQuery::limit($request, 50),
            PageQuery::offset($request),
        );
    }

    /**
     * @param array{rows: list<array<string, mixed>>, total: int} $page
     */
    private function page(array $page, int $limit, int $offset): JsonResponse
    {
        return ApiResponse::ok($page['rows'], [
            'page' => ['limit' => $limit, 'offset' => $offset, 'total' => $page['total']],
        ]);
    }

    /**
     * @template T
     *
     * @param callable(): T $action
     *
     * @return T
     */
    private function run(callable $action): mixed
    {
        try {
            return $action();
        } catch (InvalidTenantInputException $exception) {
            if ($exception->getMessage() === 'Unknown tenant.') {
                throw new NotFoundHttpException('Unknown tenant.', $exception);
            }
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        } catch (TenantLifecycleException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        } catch (TenantProvisioningException $exception) {
            if (str_contains($exception->getMessage(), 'already registered')) {
                throw new ConflictHttpException($exception->getMessage(), $exception);
            }
            throw new UnprocessableEntityHttpException('The tenant database could not be provisioned.', $exception);
        } catch (TenantNotProvisionedException $exception) {
            throw new UnprocessableEntityHttpException('The tenant database is not provisioned.', $exception);
        } catch (\Throwable $exception) {
            if (!$this->uniqueConflict($exception)) {
                throw $exception;
            }
            throw new ConflictHttpException('The tenant slug or hostname is already registered.', $exception);
        }
    }

    private function uniqueConflict(\Throwable $exception): bool
    {
        for ($current = $exception; $current instanceof \Throwable; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        if ($request->getContent() === '') {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }

        return JsonBody::read($request);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function rejectConnectionFields(array $body): void
    {
        foreach (['database', 'databaseName', 'dbname', 'password', 'username', 'host', 'connection', 'actorId', 'tenantId'] as $key) {
            if (array_key_exists($key, $body)) {
                throw new UnprocessableEntityHttpException('The request includes a field the platform does not accept.');
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function rejectPlan(array $body): void
    {
        if (array_key_exists('plan', $body) || array_key_exists('subscription', $body)) {
            throw new UnprocessableEntityHttpException('Subscription plans are not available.');
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{email: string, displayName: string, password: string}|null
     */
    private function administrator(array $body): ?array
    {
        if (!array_key_exists('administrator', $body) || $body['administrator'] === null) {
            return null;
        }
        $administrator = $body['administrator'];
        if (!is_array($administrator) || array_is_list($administrator)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }
        /** @var array<string, mixed> $administrator */
        try {
            return [
                'email' => $this->accounts->email(JsonBody::string($administrator, 'email')),
                'displayName' => $this->accounts->displayName(JsonBody::string($administrator, 'displayName')),
                'password' => $this->accounts->password(JsonBody::string($administrator, 'password')),
            ];
        } catch (InvalidTenantInputException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
    }
}
