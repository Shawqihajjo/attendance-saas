<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\TenantContext\Domain\TenantDatabaseSwitcher;
use App\TenantContext\Domain\TenantNotProvisionedException;
use App\TenantContext\Infrastructure\Doctrine\TenantAwareConnection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class FoundationApiTest extends WebTestCase
{
    public function testLivenessReturnsTheServiceEnvelope(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/health');

        self::assertResponseIsSuccessful();
        $payload = $this->decode($client->getResponse()->getContent());
        self::assertSame('ok', $payload['data']['status']);
        self::assertSame('attendance-api', $payload['data']['service']);
        self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/', $payload['data']['time']);
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testReadinessUsesThePlatformDatabaseAndLeavesTheTenantClosed(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/health/ready');

        self::assertResponseIsSuccessful();
        $payload = $this->decode($client->getResponse()->getContent());
        self::assertSame('ok', $payload['data']['status']);
        self::assertSame('ok', $payload['data']['checks']['platform_database']['status']);
        self::assertSame('unselected', $payload['data']['checks']['tenant_connection']['status']);
        self::assertSame('disabled', $payload['data']['checks']['redis']['status']);
        self::assertSame('disabled', $payload['data']['checks']['rabbitmq']['status']);
    }

    public function testProtectedAreasRejectAnonymousCalls(): void
    {
        $client = self::createClient();
        foreach (['/api/v1/platform/diagnostics', '/api/v1/terminal/diagnostics', '/api/v1/hr/diagnostics'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(401);
            self::assertSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
            $payload = $this->decode($client->getResponse()->getContent());
            self::assertSame('unauthenticated', $payload['code']);
        }
    }

    public function testValidationProbeRejectsAnEmptyName(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/api/v1/public/diagnostics/validate',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":""}',
        );

        self::assertResponseStatusCodeSame(422);
        $payload = $this->decode($client->getResponse()->getContent());
        self::assertSame('validation_failed', $payload['code']);
        self::assertSame('name', $payload['errors'][0]['field']);
    }

    public function testBrandingAndSessionArePublicFoundationPayloads(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/public/branding');
        self::assertResponseIsSuccessful();
        $branding = $this->decode($client->getResponse()->getContent());
        self::assertSame('Attendance', $branding['data']['displayName']);
        self::assertContains('sv', $branding['data']['enabledLocales']);

        $client->request('GET', '/api/v1/auth/session');
        self::assertResponseIsSuccessful();
        $session = $this->decode($client->getResponse()->getContent());
        self::assertFalse($session['data']['authenticated']);
    }

    public function testUnknownApiRoutesUseProblemJson(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/missing');

        self::assertResponseStatusCodeSame(404);
        $payload = $this->decode($client->getResponse()->getContent());
        self::assertSame('not_found', $payload['code']);
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

final class DiagnosticsCommandTest extends KernelTestCase
{
    public function testDiagnosticsCommandSucceedsWhenOptionalServicesAreDisabled(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:diagnostics'));

        $status = $tester->execute([]);

        self::assertSame(0, $status);
        self::assertStringContainsString('"status": "ok"', $tester->getDisplay());
    }

    public function testSwitchingToAnUnknownTenantDoesNotSelectADatabase(): void
    {
        self::bootKernel();
        $switcher = self::getContainer()->get(TenantDatabaseSwitcher::class);
        self::assertInstanceOf(TenantDatabaseSwitcher::class, $switcher);

        try {
            $switcher->switchTo(Uuid::v7()->toRfc4122());
            self::fail('An unknown tenant must not select a database.');
        } catch (TenantNotProvisionedException) {
        }

        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        self::assertInstanceOf(TenantAwareConnection::class, $connection);
        self::assertFalse($connection->isTenantSelected());
    }
}
