<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\TenantRegistry\Application\DeviceEnrollment;
use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Application\TenantLifecycle;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Platform\TenantRegistry\Infrastructure\Persistence\PlatformAuditEntry;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Tenant\Directory\Department;
use App\Tenant\Directory\Employee;
use App\TenantContext\Application\TenantResolver;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use App\TenantContext\Domain\TenantMismatchException;
use App\TenantContext\Domain\TenantNotProvisionedException;
use App\TenantContext\Domain\TenantUnavailableException;
use App\TenantContext\Infrastructure\Doctrine\TenantAwareConnection;
use App\TenantContext\Infrastructure\Messenger\TenantStamp;
use App\TenantContext\Infrastructure\Messenger\TenantSwitchMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

final class TenantIsolationTest extends KernelTestCase
{
    public function testATenantCannotReadAnotherTenantsRows(): void
    {
        self::bootKernel();
        $suffix = bin2hex(random_bytes(3));
        $alpha = $this->provision('alpha'.$suffix, 'alpha'.$suffix.'.localhost');
        $beta = $this->provision('beta'.$suffix, 'beta'.$suffix.'.localhost');
        $switcher = $this->switcher();
        $employees = $this->tenantManager();

        $switcher->switchTo($alpha->id());
        $adaId = Uuid::v7()->toRfc4122();
        $employees->persist(new Employee($adaId, 'Ada', 'active', new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        $employees->persist(new Department(Uuid::v7()->toRfc4122(), 'Alpha HR', new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        $employees->flush();

        $switcher->switchTo($beta->id());
        self::assertNull($employees->find(Employee::class, $adaId));
        self::assertSame([], $employees->getRepository(Department::class)->findAll());
        $employees->persist(new Employee(Uuid::v7()->toRfc4122(), 'Bo', 'active', new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        $employees->flush();
        self::assertSame(['Bo'], $this->names($employees));

        $switcher->switchTo($alpha->id());
        self::assertSame(['Ada'], $this->names($employees));
        $alphaParams = $this->connection()->getParams();
        $switcher->switchTo($beta->id());
        self::assertNotSame($alphaParams['path'] ?? null, $this->connection()->getParams()['path'] ?? null);
        $switcher->reset();
        self::assertFalse($this->connection()->isTenantSelected());
        self::assertSame('', $this->connection()->getParams()['password'] ?? '');
    }

    public function testBackgroundHandlingKeepsEachTenantAndRejectsABadStamp(): void
    {
        self::bootKernel();
        $suffix = bin2hex(random_bytes(3));
        $alpha = $this->provision('joba'.$suffix, 'joba'.$suffix.'.localhost');
        $beta = $this->provision('jobb'.$suffix, 'jobb'.$suffix.'.localhost');
        $switcher = $this->switcher();
        $employees = $this->tenantManager();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $switcher->switchTo($alpha->id());
        $employees->persist(new Employee(Uuid::v7()->toRfc4122(), 'Ada', 'active', $now));
        $employees->flush();
        $switcher->switchTo($beta->id());
        $employees->persist(new Employee(Uuid::v7()->toRfc4122(), 'Bo', 'active', $now));
        $employees->flush();
        $switcher->reset();

        $middleware = self::getContainer()->get(TenantSwitchMiddleware::class);
        self::assertInstanceOf(TenantSwitchMiddleware::class, $middleware);

        $seen = [];
        $middleware->handle(
            new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TenantStamp($alpha->id())]),
            $this->stack(function () use (&$seen, $employees): void {
                $seen = $this->names($employees);
            }),
        );
        self::assertSame(['Ada'], $seen);
        self::assertFalse($this->connection()->isTenantSelected());

        $seen = [];
        $middleware->handle(
            new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TenantStamp($beta->id())]),
            $this->stack(function () use (&$seen, $employees): void {
                $seen = $this->names($employees);
            }),
        );
        self::assertSame(['Bo'], $seen);
        self::assertFalse($this->connection()->isTenantSelected());

        $switcher->switchTo($alpha->id());
        $middleware->handle(new Envelope(new \stdClass()), $this->stack(static function (): void {}));
        self::assertTrue($this->connection()->isTenantSelected());
        $switcher->reset();

        try {
            $middleware->handle(
                new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TenantStamp(Uuid::v7()->toRfc4122())]),
                $this->stack(static function (): void {
                    self::fail('An unknown tenant must not reach the handler.');
                }),
            );
            self::fail('An unknown tenant stamp must be rejected.');
        } catch (TenantNotProvisionedException) {
        }
        self::assertFalse($this->connection()->isTenantSelected());

        $this->lifecycle()->suspend($alpha->slug(), 'cli');
        try {
            $middleware->handle(
                new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TenantStamp($alpha->id())]),
                $this->stack(static function (): void {
                    self::fail('A suspended tenant must not reach the handler.');
                }),
            );
            self::fail('A suspended tenant stamp must be rejected.');
        } catch (TenantUnavailableException) {
        }
        self::assertFalse($this->connection()->isTenantSelected());
    }

    public function testDeviceMappingFollowsTheRegistryAndLifecycleClosesTheDatabase(): void
    {
        self::bootKernel();
        $suffix = bin2hex(random_bytes(3));
        $alpha = $this->provision('deva'.$suffix, 'deva'.$suffix.'.localhost');
        $beta = $this->provision('devb'.$suffix, 'devb'.$suffix.'.localhost');
        $switcher = $this->switcher();
        $employees = $this->tenantManager();
        $switcher->switchTo($alpha->id());
        $employees->persist(new Employee(Uuid::v7()->toRfc4122(), 'Ada', 'active', new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        $employees->flush();
        $switcher->reset();

        $enrollment = self::getContainer()->get(DeviceEnrollment::class);
        self::assertInstanceOf(DeviceEnrollment::class, $enrollment);
        $pending = $enrollment->register($alpha->slug(), 'Front door', 'cli');
        $resolver = self::getContainer()->get(TenantResolver::class);
        self::assertInstanceOf(TenantResolver::class, $resolver);
        try {
            $resolver->tenantIdForActiveDevice($pending->id());
            self::fail('A pending device must not resolve a tenant.');
        } catch (TenantNotProvisionedException) {
        }

        $active = $enrollment->activate($pending->id(), 'cli');
        self::assertSame($alpha->id(), $active->tenantId());
        self::assertSame($alpha->id(), $resolver->tenantIdForActiveDevice($active->id()));
        $switcher->switchTo($resolver->tenantIdForActiveDevice($active->id()));
        self::assertSame(['Ada'], $this->names($employees));
        $switcher->reset();
        self::assertNotSame($beta->id(), $active->tenantId());

        $request = Request::create('http://deva'.$suffix.'.localhost/api/v1/tenant/context');
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set('tenant_id', $beta->id());
        $request->setSession($session);
        try {
            $resolver->resolve($request);
            self::fail('A session from another tenant must be rejected.');
        } catch (TenantMismatchException) {
        }

        $lifecycle = $this->lifecycle();
        $lifecycle->suspend($alpha->slug(), 'cli');
        try {
            $switcher->switchTo($alpha->id());
            self::fail('A suspended tenant must not be selected.');
        } catch (TenantUnavailableException) {
        }
        self::assertFalse($this->connection()->isTenantSelected());
        $lifecycle->reactivate($alpha->slug(), 'cli');
        $switcher->switchTo($alpha->id());
        self::assertSame(['Ada'], $this->names($employees));
        $switcher->reset();

        $lifecycle->decommission($alpha->slug(), 'cli');
        try {
            $switcher->switchTo($alpha->id());
            self::fail('A decommissioned tenant must not be selected.');
        } catch (TenantUnavailableException) {
        }
        self::assertFalse($this->connection()->isTenantSelected());

        $store = $this->store();
        $connection = $store->findConnection($alpha->id());
        self::assertNotNull($connection);
        $path = (string) self::getContainer()->getParameter('app.tenant_database_directory').'/'.$connection->databaseName().'.sqlite';
        self::assertFileExists($path);
        $lifecycle->dropDatabase($alpha->slug(), 'cli');
        self::assertFileDoesNotExist($path);
        try {
            $switcher->switchForMaintenance($alpha->id());
            self::fail('A decommissioned tenant must not be selected.');
        } catch (TenantUnavailableException) {
        }
        self::assertSame('dropped', $store->findConnection($alpha->id())?->credentialsState());
        self::assertFalse($this->connection()->isTenantSelected());

        $metadata = '';
        $platform = $this->platformManager();
        foreach ($platform->getRepository(PlatformAuditEntry::class)->findBy(['targetId' => $alpha->id()]) as $entry) {
            self::assertInstanceOf(PlatformAuditEntry::class, $entry);
            $metadata .= $entry->metadata();
        }
        self::assertStringContainsString('tenant.provisioned', implode(',', $store->auditActions($alpha->id())));
        self::assertStringContainsString('tenant.database_dropped', implode(',', $store->auditActions($alpha->id())));
        self::assertStringNotContainsString('password', $metadata);
        self::assertStringNotContainsString('ciphertext', $metadata);
    }

    public function testTenantCheckCommandClosesTheConnection(): void
    {
        self::bootKernel();
        $suffix = bin2hex(random_bytes(3));
        $tenant = $this->provision('check'.$suffix, 'check'.$suffix.'.localhost');
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:tenant:check'));

        self::assertSame(0, $tester->execute(['--tenant' => $tenant->slug()]));
        self::assertStringContainsString($tenant->slug().' ok', $tester->getDisplay());
        self::assertFalse($this->connection()->isTenantSelected());
    }

    private function provision(string $slug, string $hostname): Tenant
    {
        $provision = self::getContainer()->get(ProvisionTenant::class);
        self::assertInstanceOf(ProvisionTenant::class, $provision);

        return $provision->execute(new ProvisionTenantRequest($slug, 'Example AB', $hostname, 'Europe/Stockholm', 'sv', 'cli'));
    }

    private function switcher(): TenantDatabaseSwitcher
    {
        $switcher = self::getContainer()->get(TenantDatabaseSwitcher::class);
        self::assertInstanceOf(TenantDatabaseSwitcher::class, $switcher);

        return $switcher;
    }

    private function lifecycle(): TenantLifecycle
    {
        $lifecycle = self::getContainer()->get(TenantLifecycle::class);
        self::assertInstanceOf(TenantLifecycle::class, $lifecycle);

        return $lifecycle;
    }

    private function store(): TenantRegistryStore
    {
        $store = self::getContainer()->get(TenantRegistryStore::class);
        self::assertInstanceOf(TenantRegistryStore::class, $store);

        return $store;
    }

    private function tenantManager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(ManagerRegistry::class);
        self::assertInstanceOf(ManagerRegistry::class, $manager);
        $tenant = $manager->getManager('tenant');
        self::assertInstanceOf(EntityManagerInterface::class, $tenant);

        return $tenant;
    }

    private function platformManager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(ManagerRegistry::class);
        self::assertInstanceOf(ManagerRegistry::class, $manager);
        $platform = $manager->getManager('platform');
        self::assertInstanceOf(EntityManagerInterface::class, $platform);

        return $platform;
    }

    private function connection(): TenantAwareConnection
    {
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        self::assertInstanceOf(TenantAwareConnection::class, $connection);

        return $connection;
    }

    /**
     * @return list<string>
     */
    private function names(EntityManagerInterface $employees): array
    {
        $names = [];
        foreach ($employees->getRepository(Employee::class)->findAll() as $employee) {
            self::assertInstanceOf(Employee::class, $employee);
            $names[] = $employee->displayName();
        }
        sort($names);

        return $names;
    }

    /**
     * @param callable(): void $handler
     */
    private function stack(callable $handler): StackInterface
    {
        return new class($handler) implements StackInterface {
            /** @param callable(): void $handler */
            public function __construct(private $handler)
            {
            }

            public function next(): MiddlewareInterface
            {
                return new class($this->handler) implements MiddlewareInterface {
                    /** @param callable(): void $handler */
                    public function __construct(private $handler)
                    {
                    }

                    public function handle(Envelope $envelope, StackInterface $stack): Envelope
                    {
                        ($this->handler)();

                        return $envelope;
                    }
                };
            }
        };
    }
}

#[Group('api')]
final class TenantResolutionApiTest extends WebTestCase
{
    public function testHostResolvesTheTenantAndClientClaimsCannotSwitchIt(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $alphaHost = 'weba'.$suffix.'.localhost';
        $betaHost = 'webb'.$suffix.'.localhost';
        $alpha = $this->provision('weba'.$suffix, $alphaHost);
        $beta = $this->provision('webb'.$suffix, $betaHost);

        $client = self::createClient();
        $client->disableReboot();
        $client->request('GET', '/api/v1/tenant/context', server: ['HTTP_HOST' => $alphaHost]);
        self::assertResponseIsSuccessful();
        $payload = $this->decode($client->getResponse()->getContent());
        self::assertSame($alpha->slug(), $payload['data']['slug']);
        self::assertSame('Europe/Stockholm', $payload['data']['timezone']);
        self::assertSame('sv', $payload['data']['defaultLocale']);
        self::assertArrayNotHasKey('database', $payload['data']);

        $client->request('GET', '/api/v1/health/ready');
        self::assertResponseIsSuccessful();
        $ready = $this->decode($client->getResponse()->getContent());
        self::assertSame('unselected', $ready['data']['checks']['tenant_connection']['status']);

        $client->request('GET', '/api/v1/tenant/context', server: [
            'HTTP_HOST' => $alphaHost,
            'HTTP_X_FORWARDED_HOST' => $betaHost,
            'HTTP_X_TENANT_ID' => $alpha->id(),
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame($alpha->slug(), $this->decode($client->getResponse()->getContent())['data']['slug']);

        $client->request('GET', '/api/v1/tenant/context', server: [
            'HTTP_HOST' => $alphaHost,
            'HTTP_X_TENANT_ID' => $beta->id(),
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('tenant_mismatch', $this->decode($client->getResponse()->getContent())['code']);

        $client->request(
            'GET',
            '/api/v1/tenant/context',
            server: ['HTTP_HOST' => $alphaHost, 'CONTENT_TYPE' => 'application/json'],
            content: '{"database":"platform","password":"secret"}',
        );
        self::assertResponseStatusCodeSame(403);
        self::assertSame('tenant_mismatch', $this->decode($client->getResponse()->getContent())['code']);

        $client->request('GET', '/api/v1/tenant/context', server: ['HTTP_HOST' => 'localhost']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('tenant_not_found', $this->decode($client->getResponse()->getContent())['code']);

        $client->request('GET', '/api/v1/tenant/context', server: ['HTTP_HOST' => $betaHost]);
        self::assertResponseIsSuccessful();
        self::assertSame($beta->slug(), $this->decode($client->getResponse()->getContent())['data']['slug']);

        $lifecycle = self::getContainer()->get(TenantLifecycle::class);
        self::assertInstanceOf(TenantLifecycle::class, $lifecycle);
        $lifecycle->suspend($beta->slug(), 'cli');
        $client->request('GET', '/api/v1/tenant/context', server: ['HTTP_HOST' => $betaHost]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('tenant_unavailable', $this->decode($client->getResponse()->getContent())['code']);

        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        self::assertInstanceOf(TenantAwareConnection::class, $connection);
        self::assertFalse($connection->isTenantSelected());
    }

    private function provision(string $slug, string $hostname): Tenant
    {
        self::bootKernel();
        $provision = self::getContainer()->get(ProvisionTenant::class);
        self::assertInstanceOf(ProvisionTenant::class, $provision);
        $tenant = $provision->execute(new ProvisionTenantRequest($slug, 'Example AB', $hostname, 'Europe/Stockholm', 'sv', 'cli'));
        self::ensureKernelShutdown();

        return $tenant;
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
