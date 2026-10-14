<?php

declare(strict_types=1);

namespace App\Platform\DatabaseExplorer;

use App\Security\TooManyAttemptsException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Limits how often and how many explorer queries one operator can run at once.
 */
final class ExplorerQueryGate
{
    private const RATE_LIMIT = 20;

    private const RATE_WINDOW_SECONDS = 60;

    private const CONCURRENT = 2;

    private const SLOT_SECONDS = 30;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function acquire(string $actorId): string
    {
        $rateKey = 'explorer-query-rate.'.hash('sha256', $actorId);
        $rate = $this->cache->getItem($rateKey);
        $count = $rate->isHit() ? (int) $rate->get() : 0;
        if ($count >= self::RATE_LIMIT) {
            throw new TooManyAttemptsException();
        }
        $slotKey = null;
        for ($slot = 0; $slot < self::CONCURRENT; $slot++) {
            $candidate = 'explorer-query-slot.'.hash('sha256', $actorId).'.'.$slot;
            $item = $this->cache->getItem($candidate);
            if ($item->isHit()) {
                continue;
            }
            $item->set(1);
            $item->expiresAfter(self::SLOT_SECONDS);
            $this->cache->save($item);
            $slotKey = $candidate;
            break;
        }
        if ($slotKey === null) {
            throw new TooManyAttemptsException();
        }
        $rate->set($count + 1);
        $rate->expiresAfter(self::RATE_WINDOW_SECONDS);
        $this->cache->save($rate);

        return $slotKey;
    }

    public function release(string $slotKey): void
    {
        $this->cache->deleteItem($slotKey);
    }
}
