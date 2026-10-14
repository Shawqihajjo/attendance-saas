<?php

declare(strict_types=1);

namespace App\Diagnostics;

final readonly class RedisEndpoint
{
    public function __construct(
        public string $host,
        public int $port,
        public ?string $username,
        public ?string $password,
    ) {
    }

    public static function fromUrl(string $url): ?self
    {
        if ($url === '' || str_starts_with($url, 'disabled')) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            throw new \InvalidArgumentException('invalid_dsn');
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            throw new \InvalidArgumentException('invalid_dsn');
        }

        $scheme = $parts['scheme'] ?? '';
        if ($scheme !== 'redis' && $scheme !== 'rediss') {
            throw new \InvalidArgumentException('invalid_dsn');
        }

        $user = $parts['user'] ?? '';
        $pass = $parts['pass'] ?? '';
        $username = $user !== '' ? rawurldecode($user) : null;
        $password = $pass !== '' ? rawurldecode($pass) : null;

        $port = $parts['port'] ?? 6379;

        return new self($host, $port, $username, $password);
    }
}
