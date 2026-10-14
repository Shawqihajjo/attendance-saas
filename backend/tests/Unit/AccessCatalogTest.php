<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Identity\Domain\AccessCatalog;
use PHPUnit\Framework\TestCase;

final class AccessCatalogTest extends TestCase
{
    public function testPermissionsAreGrantedByRoleAndAreNotTheRoleNames(): void
    {
        $employee = AccessCatalog::permissionsFor([AccessCatalog::EMPLOYEE]);
        self::assertContains('profile.view_own', $employee);
        self::assertContains('attendance.view_own', $employee);
        self::assertContains('attendance.correction.request', $employee);
        self::assertNotContains('employees.view_assigned', $employee);

        $manager = AccessCatalog::permissionsFor([AccessCatalog::MANAGER]);
        self::assertContains('profile.view_own', $manager);
        self::assertContains('employees.view_assigned', $manager);
        self::assertContains('attendance.correction.decide', $manager);
        self::assertContains('reports.view_team', $manager);
        self::assertNotContains('employees.manage', $manager);
        self::assertNotContains('users.manage', $manager);

        $hr = AccessCatalog::permissionsFor([AccessCatalog::HR]);
        self::assertContains('employees.manage', $hr);
        self::assertContains('organization.manage', $hr);
        self::assertContains('schedules.manage', $hr);
        self::assertContains('attendance.correction.manage', $hr);
        self::assertContains('reports.view', $hr);
        self::assertContains('audit.view', $hr);
        self::assertContains('tenant.settings.manage', $hr);
        self::assertNotContains('users.manage', $hr);
        self::assertNotContains('branding.manage', $hr);
        self::assertNotContains('locales.manage', $hr);
        self::assertNotContains('timezone.manage', $hr);
        self::assertNotContains('terminals.manage', $hr);
        self::assertNotContains('permissions.manage', $hr);

        $tenantAdmin = AccessCatalog::permissionsFor([AccessCatalog::TENANT_ADMIN]);
        self::assertContains('users.manage', $tenantAdmin);
        self::assertContains('permissions.manage', $tenantAdmin);
        self::assertContains('branding.manage', $tenantAdmin);
        self::assertContains('locales.manage', $tenantAdmin);
        self::assertContains('timezone.manage', $tenantAdmin);
        self::assertContains('terminals.manage', $tenantAdmin);
        self::assertContains('tenant.configuration.manage', $tenantAdmin);
        self::assertNotContains('tenants.manage', $tenantAdmin);
        self::assertNotContains('tenants.lifecycle', $tenantAdmin);
        self::assertNotContains('platform.administer', $tenantAdmin);
        self::assertNotContains('platform.database.explore', $tenantAdmin);

        $platform = AccessCatalog::permissionsFor([AccessCatalog::PLATFORM_ADMIN]);
        self::assertSame(['tenants.manage', 'tenants.lifecycle', 'platform.administer', 'platform.database.explore'], $platform);
        self::assertNotContains(AccessCatalog::PLATFORM_ADMIN, $platform);
    }
}
