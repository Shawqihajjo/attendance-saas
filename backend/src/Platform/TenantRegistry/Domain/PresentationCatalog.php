<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Domain;

/**
 * Allowed presentation codes. Adding a format means adding it here and in the frontend formatter.
 */
final class PresentationCatalog
{
    /**
     * @return list<string>
     */
    public static function dateFormats(): array
    {
        return ['iso', 'dmy', 'mdy'];
    }

    /**
     * @return list<string>
     */
    public static function timeFormats(): array
    {
        return ['24h', '12h'];
    }
}
