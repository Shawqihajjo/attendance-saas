<?php

declare(strict_types=1);

namespace App\Diagnostics;

use App\Shared\Logging\SecretRedactor;
use App\TenantContext\Infrastructure\Doctrine\TenantAwareConnection;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

final class ReadinessChecker
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
        private readonly string $redisUrl,
        private readonly string $messengerDsn,
    ) {
    }

    public function report(): DiagnosticReport
    {
        $checks = [
            'platform_database' => $this->platformDatabase(),
            'tenant_connection' => $this->tenantConnection(),
            'redis' => $this->redis(),
            'rabbitmq' => $this->rabbitmq(),
        ];

        $failed = false;
        foreach ($checks as $check) {
            if ($check->status === 'failed') {
                $failed = true;
                break;
            }
        }

        return new DiagnosticReport($failed ? 'failed' : 'ok', $checks);
    }

    private function platformDatabase(): DiagnosticCheck
    {
        try {
            $value = $this->doctrine->getConnection('platform')->executeQuery('SELECT 1')->fetchOne();
            if ((string) $value !== '1') {
                return new DiagnosticCheck('failed', 'unexpected_result');
            }

            return new DiagnosticCheck('ok');
        } catch (\Throwable $exception) {
            $this->logFailure('platform_database', $exception);

            return new DiagnosticCheck('failed', 'unavailable');
        }
    }

    private function tenantConnection(): DiagnosticCheck
    {
        $connection = $this->doctrine->getConnection('tenant');
        if (!$connection instanceof TenantAwareConnection) {
            return new DiagnosticCheck('failed', 'wrong_connection_type');
        }

        if ($connection->isTenantSelected()) {
            return new DiagnosticCheck('failed', 'selected_unexpectedly');
        }

        return new DiagnosticCheck('unselected');
    }

    private function redis(): DiagnosticCheck
    {
        try {
            $endpoint = RedisEndpoint::fromUrl($this->redisUrl);
        } catch (\InvalidArgumentException) {
            return new DiagnosticCheck('failed', 'invalid_dsn');
        }
        if (!$endpoint instanceof RedisEndpoint) {
            return new DiagnosticCheck('disabled');
        }

        if (!extension_loaded('redis')) {
            return new DiagnosticCheck('failed', 'extension_missing');
        }

        try {
            $redis = new \Redis();
            $connected = $redis->connect($endpoint->host, $endpoint->port, 2.0);
            if ($connected !== true) {
                return new DiagnosticCheck('failed', 'unavailable');
            }
            if ($endpoint->password !== null) {
                $authed = $endpoint->username === null
                    ? $redis->auth($endpoint->password)
                    : $redis->auth([$endpoint->username, $endpoint->password]);
                if ($authed !== true) {
                    $redis->close();

                    return new DiagnosticCheck('failed', 'unavailable');
                }
            }
            $pong = $redis->ping();
            $redis->close();
            $pongOk = $pong === true || (is_string($pong) && str_contains($pong, 'PONG'));
            if (!$pongOk) {
                return new DiagnosticCheck('failed', 'unexpected_result');
            }

            return new DiagnosticCheck('ok');
        } catch (\Throwable $exception) {
            $this->logFailure('redis', $exception);

            return new DiagnosticCheck('failed', 'unavailable');
        }
    }

    private function rabbitmq(): DiagnosticCheck
    {
        if (!str_starts_with($this->messengerDsn, 'amqp://') && !str_starts_with($this->messengerDsn, 'amqps://')) {
            return new DiagnosticCheck('disabled');
        }

        if (!extension_loaded('amqp')) {
            return new DiagnosticCheck('failed', 'extension_missing');
        }

        $parts = parse_url($this->messengerDsn);
        if ($parts === false || !isset($parts['host'])) {
            return new DiagnosticCheck('failed', 'invalid_dsn');
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        $segments = explode('/', $path);
        $vhost = rawurldecode($segments[0]);
        if ($vhost === '') {
            $vhost = '/';
        }

        try {
            $connection = new \AMQPConnection([
                'host' => (string) $parts['host'],
                'port' => isset($parts['port']) ? (int) $parts['port'] : 5672,
                'login' => rawurldecode((string) ($parts['user'] ?? 'guest')),
                'password' => rawurldecode((string) ($parts['pass'] ?? 'guest')),
                'vhost' => $vhost,
                'connect_timeout' => 3.0,
            ]);
            $connection->connect();
            $connected = $connection->isConnected();
            $connection->disconnect();
            if (!$connected) {
                return new DiagnosticCheck('failed', 'unavailable');
            }

            return new DiagnosticCheck('ok');
        } catch (\Throwable $exception) {
            $this->logFailure('rabbitmq', $exception);

            return new DiagnosticCheck('failed', 'unavailable');
        }
    }

    private function logFailure(string $check, \Throwable $exception): void
    {
        $this->logger->error('Readiness check failed.', [
            'check' => $check,
            'exception_class' => $exception::class,
            'message' => SecretRedactor::redact($exception->getMessage()),
        ]);
    }
}
