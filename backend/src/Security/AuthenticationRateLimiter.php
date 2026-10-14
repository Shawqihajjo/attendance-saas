<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AuthenticationRateLimiter
{
    /** @var array<string, array{count: int, expires: int}> */
    private static array $local = [];

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function guard(string $bucket, int $limit): void
    {
        if ($this->count($bucket) >= $limit) {
            throw new TooManyAttemptsException();
        }
    }

    public function hit(string $bucket, int $windowSeconds): void
    {
        $count = $this->count($bucket) + 1;
        $expires = time() + $windowSeconds;
        $known = self::$local[$bucket] ?? null;
        if (is_array($known) && $known['expires'] > time()) {
            $expires = $known['expires'];
        }
        self::$local[$bucket] = ['count' => $count, 'expires' => $expires];
        $item = $this->cache->getItem($this->key($bucket));
        $item->set($count);
        $item->expiresAfter(max(1, $expires - time()));
        $this->cache->save($item);
    }

    private function count(string $bucket): int
    {
        $item = $this->cache->getItem($this->key($bucket));
        $cached = $item->isHit() ? (int) $item->get() : 0;
        $known = self::$local[$bucket] ?? null;
        $local = is_array($known) && $known['expires'] > time() ? $known['count'] : 0;

        return max($cached, $local);
    }

    private function key(string $bucket): string
    {
        return 'auth-rate.'.hash('sha256', $bucket);
    }
}
