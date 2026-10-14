<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Domain;

/**
 * Usage compared with a platform-owned limit.
 *
 * null limit means unlimited. A used count above the limit is over the limit and still preserved.
 * Further increases are blocked once used is greater than or equal to the limit.
 */
final class QuotaUsage
{
    /**
     * @return array{used: int|null, limit: int|null, remaining: int|null, level: string, overLimit: bool}
     */
    public static function metric(?int $used, ?int $limit): array
    {
        if ($used === null) {
            return [
                'used' => null,
                'limit' => $limit,
                'remaining' => null,
                'level' => 'unavailable',
                'overLimit' => false,
            ];
        }
        if ($limit === null) {
            return [
                'used' => $used,
                'limit' => null,
                'remaining' => null,
                'level' => 'ok',
                'overLimit' => false,
            ];
        }
        $over = $used > $limit;
        $level = 'ok';
        if ($over) {
            $level = 'over';
        } elseif ($used >= $limit) {
            $level = 'reached';
        } elseif ($limit > 0 && $used * 5 >= $limit * 4) {
            $level = 'warning';
        }

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'level' => $level,
            'overLimit' => $over,
        ];
    }
}
