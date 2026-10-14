<?php

declare(strict_types=1);

namespace App\Platform\DatabaseExplorer;

/**
 * A statement rebuilt from the parser tree. The original text is not executed.
 */
final class PreparedExplorerQuery
{
    public function __construct(
        public readonly string $kind,
        public readonly string $sql,
        public readonly string $queryHash,
        public readonly ?string $table,
        public readonly int $rowCap,
        public readonly ?string $column = null,
    ) {
    }
}
