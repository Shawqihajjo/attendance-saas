<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\AccountInput;
use App\Identity\Application\OpenAccounts;
use App\Identity\Domain\AccessCatalog;
use App\Identity\Domain\Permission;
use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Identity\Infrastructure\Security\AccessGate;
use App\Platform\TenantRegistry\Application\PlatformAuditRecorder;
use App\Platform\TenantRegistry\Application\ReadTenantConfiguration;
use App\Platform\TenantRegistry\Application\TenantQuotaService;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Platform\TenantRegistry\Domain\TenantInputValidator;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Clock\UtcClock;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\PageQuery;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TenantAdminController
{
    public function __construct(
        private readonly AccessGate $access,
        private readonly TenantAccountStore $accounts,
        private readonly OpenAccounts $openAccounts,
        private readonly AccountInput $input,
        private readonly TenantInputValidator $validator,
        private readonly TenantRegistryStore $registry,
        private readonly PlatformAuditRecorder $platformAudit,
        private readonly ReadTenantConfiguration $configurations,
        private readonly UtcClock $clock,
        private readonly TenantQuotaService $quotas,
    ) {
    }

    #[Route('/api/v1/tenant/usage', name: 'tenant_usage', methods: ['GET'])]
    public function usage(): JsonResponse
    {
        $this->access->require(Permission::TenantConfigurationManage);

        return ApiResponse::ok($this->quotas->currentTenantReport());
    }

    #[Route('/api/v1/hr/users', name: 'hr_users', methods: ['GET'])]
    public function users(): JsonResponse
    {
        $this->access->require(Permission::UsersManage);

        return ApiResponse::ok($this->accounts->users());
    }

    #[Route('/api/v1/hr/users', name: 'hr_user_create', methods: ['POST'])]
    public function createUser(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::UsersManage);
        $body = JsonBody::read($request);
        try {
            $account = $this->openAccounts->createInCurrentDatabase(
                JsonBody::string($body, 'email'),
                JsonBody::string($body, 'displayName'),
                JsonBody::string($body, 'password'),
                $this->input->tenantRoles(JsonBody::stringList($body, 'roles')),
            );
        } catch (InvalidTenantInputException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        } catch (UniqueConstraintViolationException $exception) {
            throw new UnprocessableEntityHttpException('An account with this email already exists.', $exception);
        }
        $this->audit($request, $actor->id(), 'user.created', 'user', $account->id, [
            'email' => $account->email,
            'roles' => implode(',', $account->roles),
        ]);

        return ApiResponse::ok([
            'id' => $account->id,
            'email' => $account->email,
            'displayName' => $account->displayName,
            'roles' => $account->roles,
            'employeeId' => $account->employeeId,
        ], [], 201);
    }

    #[Route('/api/v1/hr/users/{id}/roles', name: 'hr_user_roles', methods: ['PUT'], requirements: ['id' => Requirement::UUID])]
    public function replaceRoles(Request $request, string $id): JsonResponse
    {
        $actor = $this->access->require(Permission::PermissionsManage);
        $target = $this->accounts->find($id);
        if ($target === null) {
            throw new NotFoundHttpException('User was not found.');
        }
        try {
            $roles = $this->input->tenantRoles(JsonBody::stringList(JsonBody::read($request), 'roles'));
        } catch (InvalidTenantInputException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        if ($actor->id() === $id && in_array(AccessCatalog::TENANT_ADMIN, $target->roles, true) && !in_array(AccessCatalog::TENANT_ADMIN, $roles, true)) {
            throw new AccessDeniedException();
        }
        $this->accounts->replaceRoles($id, $roles);
        $this->audit($request, $actor->id(), 'user.roles_replaced', 'user', $id, [
            'roles' => implode(',', $roles),
        ]);

        return ApiResponse::ok(['id' => $id, 'roles' => $roles]);
    }

    #[Route('/api/v1/hr/users/{id}/disable', name: 'hr_user_disable', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function disable(Request $request, string $id): JsonResponse
    {
        $actor = $this->access->require(Permission::UsersManage);
        if ($actor->id() === $id) {
            throw new AccessDeniedException();
        }
        if ($this->accounts->find($id) === null) {
            throw new NotFoundHttpException('User was not found.');
        }
        $this->accounts->disable($id, $this->clock->now());
        $this->audit($request, $actor->id(), 'user.disabled', 'user', $id, []);

        return ApiResponse::ok(['id' => $id, 'status' => 'disabled']);
    }

    #[Route('/api/v1/hr/audit', name: 'hr_audit', methods: ['GET'])]
    public function auditLog(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::AuditView);
        $this->audit($request, $actor->id(), 'audit.viewed', 'tenant', (string) $actor->tenantId(), []);

        $page = $this->accounts->recentAudit(PageQuery::limit($request, 50), PageQuery::offset($request));

        return ApiResponse::ok($page['records'], ['page' => $page['page']]);
    }

    #[Route('/api/v1/hr/settings/photo-policy', name: 'hr_photo_policy', methods: ['PATCH'])]
    public function photoPolicy(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::TenantSettingsManage);
        $required = JsonBody::bool(JsonBody::read($request), 'photoRequired');
        $this->settings($actor->tenantId())->changePhotoRequired($required);
        $this->registry->flush();
        $this->audit($request, $actor->id(), 'tenant.photo_policy_changed', 'tenant', (string) $actor->tenantId(), [
            'photoRequired' => $required,
        ]);

        return ApiResponse::ok(['photoRequired' => $required]);
    }

    #[Route('/api/v1/hr/settings/timezone', name: 'hr_timezone', methods: ['PATCH'])]
    public function timezone(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::TimezoneManage);
        try {
            $timezone = $this->validator->timezone(JsonBody::string(JsonBody::read($request), 'timezone'));
        } catch (InvalidTenantInputException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        $this->settings($actor->tenantId())->changeTimezone($timezone);
        $this->registry->flush();
        $this->changed($request, $actor->id(), (string) $actor->tenantId(), 'tenant.timezone_changed', ['timezone' => $timezone]);

        return ApiResponse::ok(['timezone' => $timezone]);
    }

    #[Route('/api/v1/hr/settings/locales', name: 'hr_locales', methods: ['PUT'])]
    public function locales(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::LocalesManage);
        $body = JsonBody::read($request);
        try {
            $locales = [];
            foreach (JsonBody::stringList($body, 'locales') as $locale) {
                $locales[$this->validator->locale($locale)] = $this->validator->locale($locale);
            }
            $defaultLocale = $this->validator->locale(JsonBody::string($body, 'defaultLocale'));
        } catch (InvalidTenantInputException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        $codes = array_values($locales);
        if ($codes === [] || !in_array($defaultLocale, $codes, true)) {
            throw new UnprocessableEntityHttpException('The default locale must be one of the enabled locales.');
        }
        $tenantId = (string) $actor->tenantId();
        $this->registry->replaceLocales($tenantId, $codes, $defaultLocale);
        $this->changed($request, $actor->id(), $tenantId, 'tenant.locales_changed', [
            'locales' => implode(',', $codes),
            'defaultLocale' => $defaultLocale,
        ]);

        return ApiResponse::ok(['locales' => $codes, 'defaultLocale' => $defaultLocale]);
    }

    #[Route('/api/v1/hr/settings/regional', name: 'hr_regional', methods: ['PATCH'])]
    public function regional(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::TenantSettingsManage);
        $body = JsonBody::read($request);
        try {
            $dateFormat = $this->validator->dateFormat(JsonBody::string($body, 'dateFormat'));
            $timeFormat = $this->validator->timeFormat(JsonBody::string($body, 'timeFormat'));
            $weekStartsOn = $body['weekStartsOn'] ?? null;
            if (!is_int($weekStartsOn)) {
                throw new UnprocessableEntityHttpException('The request body is invalid.');
            }
            $weekStartsOn = $this->validator->weekStartsOn($weekStartsOn);
        } catch (InvalidTenantInputException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        $tenantId = (string) $actor->tenantId();
        $this->settings($tenantId)->changeRegional($dateFormat, $timeFormat, $weekStartsOn);
        $this->registry->flush();
        $this->changed($request, $actor->id(), $tenantId, 'tenant.regional_changed', [
            'dateFormat' => $dateFormat,
            'timeFormat' => $timeFormat,
            'weekStartsOn' => $weekStartsOn,
        ]);

        return ApiResponse::ok($this->configurations->forTenantId($tenantId)->toArray());
    }

    #[Route('/api/v1/hr/branding', name: 'hr_branding', methods: ['PATCH'])]
    public function branding(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::BrandingManage);
        $body = JsonBody::read($request);
        $tenantId = (string) $actor->tenantId();
        $branding = $this->registry->findBranding($tenantId);
        if ($branding === null) {
            throw new NotFoundHttpException('Branding was not found.');
        }
        $changed = false;
        try {
            if (array_key_exists('companyName', $body) || array_key_exists('displayName', $body)) {
                $name = $body['companyName'] ?? $body['displayName'] ?? null;
                if (!is_string($name)) {
                    throw new UnprocessableEntityHttpException('The request body is invalid.');
                }
                $branding->rename($this->input->displayName($name));
                $changed = true;
            }
            if (array_key_exists('logoUrl', $body)) {
                $branding->changeLogo($this->assetUrl($body['logoUrl']));
                $changed = true;
            }
            if (array_key_exists('faviconUrl', $body)) {
                $branding->changeFavicon($this->assetUrl($body['faviconUrl']));
                $changed = true;
            }
            if (array_key_exists('colors', $body)) {
                $colors = $body['colors'];
                if (!is_array($colors)) {
                    throw new UnprocessableEntityHttpException('The request body is invalid.');
                }
                $background = $colors['background'] ?? $colors['surface'] ?? null;
                $primary = $colors['primary'] ?? null;
                $secondary = $colors['secondary'] ?? null;
                $accent = $colors['accent'] ?? null;
                $text = $colors['text'] ?? null;
                if (!is_string($primary) || !is_string($secondary) || !is_string($accent) || !is_string($text) || !is_string($background)) {
                    throw new UnprocessableEntityHttpException('The request body is invalid.');
                }
                $branding->recolor(
                    $this->validator->color($primary),
                    $this->validator->color($secondary),
                    $this->validator->color($accent),
                    $this->validator->color($background),
                    $this->validator->color($text),
                );
                $changed = true;
            }
        } catch (InvalidTenantInputException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        if (!$changed) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }
        $this->registry->flush();
        $this->changed($request, $actor->id(), $tenantId, 'tenant.branding_changed', [
            'displayName' => $branding->displayName(),
        ]);

        return ApiResponse::ok($this->configurations->forTenantId($tenantId)->toArray());
    }

    private function assetUrl(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }

        return $this->validator->assetUrl($value);
    }

    private function settings(?string $tenantId): \App\Platform\TenantRegistry\Infrastructure\Persistence\TenantSetting
    {
        if ($tenantId === null) {
            throw new AccessDeniedException();
        }
        $settings = $this->registry->findSettings($tenantId);
        if ($settings === null) {
            throw new NotFoundHttpException('Tenant settings were not found.');
        }

        return $settings;
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    private function audit(Request $request, string $actorId, string $action, string $targetType, string $targetId, array $metadata): void
    {
        $requestId = $request->attributes->get('request_id');
        $this->accounts->audit(
            $action,
            'tenant_user',
            $actorId,
            $targetType,
            $targetId,
            is_string($requestId) ? $requestId : null,
            $metadata,
            $this->clock->now(),
        );
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    private function changed(Request $request, string $actorId, string $tenantId, string $action, array $metadata): void
    {
        $this->audit($request, $actorId, $action, 'tenant', $tenantId, $metadata);
        $this->platformAudit->record($action, 'tenant', $tenantId, $actorId, $metadata, 'tenant_user');
        $this->registry->flush();
    }
}
