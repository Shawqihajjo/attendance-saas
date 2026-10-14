<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Identity\Application\OpenAccounts;
use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Application\TenantLifecycle;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TenantConfigurationApiTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    public function testTenantsKeepSeparateThemesLanguagesAndTimezones(): void
    {
        $client = self::createClient();
        $suffix = bin2hex(random_bytes(3));
        $alphaHost = 'themea'.$suffix.'.localhost';
        $betaHost = 'themeb'.$suffix.'.localhost';
        $this->provision('themea'.$suffix, 'Alpha AB', $alphaHost, 'Europe/Stockholm', 'sv');
        $this->provision('themeb'.$suffix, 'Beta AB', $betaHost, 'America/New_York', 'en');

        $this->call($client, 'GET', $alphaHost, '/api/v1/public/branding');
        self::assertResponseIsSuccessful();
        self::assertSame('max-age=0, must-revalidate, private', $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('Host', (string) $client->getResponse()->headers->get('Vary'));
        $alpha = $this->payload($client);
        self::assertSame('Alpha AB', $alpha['companyName']);
        self::assertSame('Alpha AB', $alpha['displayName']);
        self::assertSame('Europe/Stockholm', $alpha['timezone']);
        self::assertSame('sv', $alpha['defaultLanguage']);
        self::assertContains('en', $alpha['supportedLanguages']);
        self::assertContains('sv', $alpha['supportedLanguages']);
        self::assertSame('#1F4B99', $alpha['colors']['primary']);
        self::assertSame($alpha['colors']['background'], $alpha['colors']['surface']);
        self::assertSame('iso', $alpha['dateFormat']);
        self::assertSame('24h', $alpha['timeFormat']);
        self::assertSame(1, $alpha['weekStartsOn']);
        self::assertTrue($alpha['attendance']['photoRequired']);
        self::assertArrayNotHasKey('tenantId', $alpha);
        self::assertArrayNotHasKey('database', $alpha);

        $this->call($client, 'GET', $betaHost, '/api/v1/public/branding');
        self::assertResponseIsSuccessful();
        $beta = $this->payload($client);
        self::assertSame('Beta AB', $beta['companyName']);
        self::assertSame('America/New_York', $beta['timezone']);
        self::assertSame('en', $beta['defaultLanguage']);
        self::assertNotSame($alpha['timezone'], $beta['timezone']);
        self::assertNotSame($alpha['companyName'], $beta['companyName']);

        $this->call($client, 'GET', 'localhost', '/api/v1/public/branding');
        self::assertResponseIsSuccessful();
        $platform = $this->payload($client);
        self::assertSame('Attendance', $platform['companyName']);
        self::assertSame('UTC', $platform['timezone']);
        self::assertContains('sv', $platform['enabledLocales']);

        $this->call($client, 'GET', 'missing'.$suffix.'.localhost', '/api/v1/public/branding');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('tenant_not_found', $this->problem($client)['code']);

        $this->accounts()->tenantUser('themea'.$suffix, 'admin@alpha.example', 'Ada Admin', self::PASSWORD, ['tenant_admin']);
        $this->accounts()->tenantUser('themea'.$suffix, 'hr@alpha.example', 'Harper HR', self::PASSWORD, ['hr']);
        $this->login($client, $alphaHost, 'hr@alpha.example');
        $this->call($client, 'PATCH', $alphaHost, '/api/v1/hr/branding', ['displayName' => 'Hidden']);
        self::assertResponseStatusCodeSame(403);
        $this->call($client, 'PATCH', $alphaHost, '/api/v1/hr/settings/regional', [
            'dateFormat' => 'dmy',
            'timeFormat' => '12h',
            'weekStartsOn' => 0,
        ]);
        self::assertResponseIsSuccessful();

        $this->login($client, $alphaHost, 'admin@alpha.example');
        $this->call($client, 'PATCH', $alphaHost, '/api/v1/hr/settings/timezone', ['timezone' => 'UTC+1']);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'PATCH', $alphaHost, '/api/v1/hr/settings/timezone', ['timezone' => 'Europe/London']);
        self::assertResponseIsSuccessful();
        $this->call($client, 'PUT', $alphaHost, '/api/v1/hr/settings/locales', ['locales' => ['fr'], 'defaultLocale' => 'fr']);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'PUT', $alphaHost, '/api/v1/hr/settings/locales', ['locales' => ['en'], 'defaultLocale' => 'en']);
        self::assertResponseIsSuccessful();
        $this->call($client, 'PATCH', $alphaHost, '/api/v1/hr/branding', [
            'companyName' => 'Alpha Attendance',
            'logoUrl' => 'http://cdn.example/logo.png',
            'faviconUrl' => 'https://cdn.example/favicon.png',
            'colors' => [
                'primary' => '#AA1100',
                'secondary' => '#220011',
                'accent' => '#CC5500',
                'background' => '#FFF8F4',
                'text' => '#1A0A06',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
        $this->call($client, 'PATCH', $alphaHost, '/api/v1/hr/branding', [
            'companyName' => 'Alpha Attendance',
            'logoUrl' => 'https://cdn.example/alpha-logo.png',
            'faviconUrl' => 'https://cdn.example/alpha-favicon.png',
            'colors' => [
                'primary' => '#AA1100',
                'secondary' => '#220011',
                'accent' => '#CC5500',
                'background' => '#FFF8F4',
                'text' => '#1A0A06',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->call($client, 'GET', $alphaHost, '/api/v1/public/branding');
        self::assertResponseIsSuccessful();
        $updated = $this->payload($client);
        self::assertSame('Alpha Attendance', $updated['companyName']);
        self::assertSame('https://cdn.example/alpha-logo.png', $updated['logoUrl']);
        self::assertSame('https://cdn.example/alpha-favicon.png', $updated['faviconUrl']);
        self::assertSame('#AA1100', $updated['colors']['primary']);
        self::assertSame('#220011', $updated['colors']['secondary']);
        self::assertSame('#FFF8F4', $updated['colors']['background']);
        self::assertSame('#1A0A06', $updated['colors']['text']);
        self::assertSame('Europe/London', $updated['timezone']);
        self::assertSame(['en'], $updated['supportedLanguages']);
        self::assertSame('en', $updated['defaultLanguage']);
        self::assertSame('dmy', $updated['dateFormat']);
        self::assertSame('12h', $updated['timeFormat']);
        self::assertSame(0, $updated['weekStartsOn']);

        $client->getCookieJar()->clear();
        $this->call($client, 'GET', $betaHost, '/api/v1/public/branding');
        self::assertResponseIsSuccessful();
        $betaAfter = $this->payload($client);
        self::assertSame('Beta AB', $betaAfter['companyName']);
        self::assertSame('America/New_York', $betaAfter['timezone']);
        self::assertSame('#1F4B99', $betaAfter['colors']['primary']);
        self::assertNotSame($updated['colors']['primary'], $betaAfter['colors']['primary']);

        $lifecycle = self::getContainer()->get(TenantLifecycle::class);
        self::assertInstanceOf(TenantLifecycle::class, $lifecycle);
        $lifecycle->suspend('themeb'.$suffix, 'cli');
        $this->call($client, 'GET', $betaHost, '/api/v1/public/branding');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('tenant_unavailable', $this->problem($client)['code']);
        $this->call($client, 'GET', $alphaHost, '/api/v1/public/branding');
        self::assertResponseIsSuccessful();
        self::assertSame('Alpha Attendance', $this->payload($client)['companyName']);
    }

    private function provision(string $slug, string $legalName, string $hostname, string $timezone, string $locale): void
    {
        $provision = self::getContainer()->get(ProvisionTenant::class);
        self::assertInstanceOf(ProvisionTenant::class, $provision);
        $provision->execute(new ProvisionTenantRequest($slug, $legalName, $hostname, $timezone, $locale, 'cli'));
    }

    private function accounts(): OpenAccounts
    {
        $accounts = self::getContainer()->get(OpenAccounts::class);
        self::assertInstanceOf(OpenAccounts::class, $accounts);

        return $accounts;
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
    private function payload(KernelBrowser $client): array
    {
        $decoded = $this->decode($client->getResponse()->getContent());
        self::assertIsArray($decoded['data']);

        return $decoded['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function problem(KernelBrowser $client): array
    {
        $payload = $this->decode($client->getResponse()->getContent());
        unset($payload['requestId']);

        return $payload;
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
