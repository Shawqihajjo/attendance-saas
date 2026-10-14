<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Identity\Application\OpenAccounts;
use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class SecurityControlsApiTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    public function testSignInFailuresAreLimitedAndAuditedWithoutSecrets(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $host = $this->host('sec');
        $this->accounts()->tenantUser($this->slug($host), 'ada@example.com', 'Ada', self::PASSWORD, ['tenant_admin']);
        $requestId = 'req-security-01';

        $this->login($client, $host, 'ada@example.com', 'wrong-password-value', [
            'HTTP_X_REQUEST_ID' => $requestId,
        ]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString("default-src 'none'", (string) $client->getResponse()->headers->get('Content-Security-Policy'));

        $this->login($client, $host, 'ada@example.com', 'wrong-password-value', [
            'HTTP_ORIGIN' => 'http://evil.example',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('csrf_rejected', $this->problem($client)['code']);

        $this->login($client, $host, 'ada@example.com', self::PASSWORD, [
            'HTTP_ORIGIN' => 'http://'.$host,
        ]);
        self::assertResponseIsSuccessful();
        $cookie = $this->cookie($client);
        $setCookie = strtolower((string) $client->getResponse()->headers->get('Set-Cookie'));
        self::assertStringContainsString('httponly', $setCookie);
        self::assertStringContainsString('samesite=lax', $setCookie);
        self::assertStringNotContainsString(self::PASSWORD, $setCookie);

        $this->call($client, 'GET', $host, '/api/v1/hr/integrations');
        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Access-Control-Allow-Origin'));

        $this->call($client, 'GET', $host, '/api/v1/hr/audit');
        self::assertResponseIsSuccessful();
        $audit = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString(self::PASSWORD, $audit);
        self::assertStringNotContainsString('wrong-password-value', $audit);
        self::assertStringContainsString('auth.login_failed', $audit);
        self::assertStringContainsString('auth.login_succeeded', $audit);
        self::assertStringContainsString($requestId, $audit);
        self::assertStringContainsString('"result":"failure"', $audit);
        self::assertStringContainsString('"result":"success"', $audit);
        self::assertStringContainsString('config.integrations_viewed', $audit);
        self::assertStringContainsString('127.0.0.1', $audit);

        $other = $this->host('other');
        $this->call($client, 'GET', $other, '/api/v1/hr/audit', $cookie);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('tenant_mismatch', $this->problem($client)['code']);

        $client->getCookieJar()->clear();
        $this->login($client, $host, 'ada@example.com', self::PASSWORD);
        $this->call($client, 'POST', $host, '/api/v1/auth/logout');
        self::assertResponseIsSuccessful();
        $this->accounts()->tenantUser($this->slug($host), 'hr-audit@example.com', 'Harper', self::PASSWORD, ['hr']);
        $this->login($client, $host, 'hr-audit@example.com', self::PASSWORD);
        $this->call($client, 'GET', $host, '/api/v1/hr/audit');
        self::assertStringContainsString('auth.logout', (string) $client->getResponse()->getContent());
    }

    public function testRepeatedSignInFailuresReturnRateLimited(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $host = $this->host('rate');
        $this->accounts()->tenantUser($this->slug($host), 'ada@example.com', 'Ada', self::PASSWORD, ['employee']);

        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $this->login($client, $host, 'ada@example.com', 'wrong-password-value');
            self::assertResponseStatusCodeSame(401);
        }
        $this->login($client, $host, 'ada@example.com', 'wrong-password-value');
        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->problem($client)['code']);
        self::assertStringNotContainsString(self::PASSWORD, (string) $client->getResponse()->getContent());
    }

    public function testCorsReflectsOnlyTheRequestHost(): void
    {
        $client = self::createClient();
        $host = $this->host('cors');
        $client->request('OPTIONS', 'http://'.$host.'/api/v1/auth/login', server: [
            'HTTP_HOST' => $host,
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($client->getResponse()->headers->has('Access-Control-Allow-Origin'));

        $client->request('POST', 'http://'.$host.'/api/v1/auth/login', server: [
            'HTTP_HOST' => $host,
            'HTTP_ORIGIN' => 'http://evil.example',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], content: '{"email":"ada@example.com","password":"not-the-password"}');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('csrf_rejected', $this->problem($client)['code']);
        self::assertFalse($client->getResponse()->headers->has('Access-Control-Allow-Origin'));

        $client->request('OPTIONS', 'http://'.$host.'/api/v1/auth/login', server: [
            'HTTP_HOST' => $host,
            'HTTP_ORIGIN' => 'http://'.$host,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('http://'.$host, $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertNotSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    private function host(string $prefix): string
    {
        $suffix = bin2hex(random_bytes(3));
        $host = $prefix.$suffix.'.localhost';
        $provision = self::getContainer()->get(ProvisionTenant::class);
        self::assertInstanceOf(ProvisionTenant::class, $provision);
        $provision->execute(new ProvisionTenantRequest($prefix.$suffix, 'Example AB', $host, 'Europe/Stockholm', 'en', 'cli'));

        return $host;
    }

    private function accounts(): OpenAccounts
    {
        $accounts = self::getContainer()->get(OpenAccounts::class);
        self::assertInstanceOf(OpenAccounts::class, $accounts);

        return $accounts;
    }

    private function slug(string $host): string
    {
        return explode('.', $host, 2)[0];
    }

    /**
     * @param array<string, string> $server
     */
    private function login(KernelBrowser $client, string $host, string $email, string $password, array $server = []): void
    {
        $client->getCookieJar()->clear();
        $server['HTTP_HOST'] = $host;
        $server['HTTP_ACCEPT'] = 'application/json';
        $server['CONTENT_TYPE'] = 'application/json';
        $client->request('POST', 'http://'.$host.'/api/v1/auth/login', server: $server, content: json_encode([
            'email' => $email,
            'password' => $password,
        ], JSON_THROW_ON_ERROR));
    }

    private function call(KernelBrowser $client, string $method, string $host, string $path, ?Cookie $cookie = null): void
    {
        $server = ['HTTP_HOST' => $host, 'HTTP_ACCEPT' => 'application/json'];
        if ($cookie !== null) {
            $client->getCookieJar()->clear();
            $client->getCookieJar()->set(new Cookie($cookie->getName(), $cookie->getValue(), null, '/', $host));
        }
        $client->request($method, 'http://'.$host.$path, server: $server);
    }

    private function cookie(KernelBrowser $client): Cookie
    {
        $cookies = $client->getCookieJar()->all();
        self::assertNotEmpty($cookies);

        return $cookies[array_key_last($cookies)];
    }

    /**
     * @return array<string, mixed>
     */
    private function problem(KernelBrowser $client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        unset($payload['requestId']);

        return $payload;
    }
}
