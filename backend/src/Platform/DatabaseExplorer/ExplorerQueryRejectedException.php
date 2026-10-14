<?php

declare(strict_types=1);

namespace App\Platform\DatabaseExplorer;

use App\Shared\Http\ApiProblem;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ExplorerQueryRejectedException extends UnprocessableEntityHttpException implements ApiProblem
{
    public function __construct(
        string $message = 'Only a single read-only query is allowed.',
        private readonly string $category = 'rejected',
        private readonly ?string $queryHash = null,
    ) {
        parent::__construct($message);
    }

    public function category(): string
    {
        return $this->category;
    }

    public function queryHash(): ?string
    {
        return $this->queryHash;
    }

    public function problemCode(): string
    {
        return $this->category === 'timeout' ? 'query_timeout' : 'query_rejected';
    }

    public function problemTitle(): string
    {
        return $this->category === 'timeout' ? 'Query timed out' : 'Query rejected';
    }

    public static function categoryFor(\Throwable $error): string
    {
        $message = strtolower($error->getMessage());
        if (
            str_contains($message, 'maximum statement execution time')
            || str_contains($message, 'query execution was interrupted')
            || str_contains($message, 'max_execution_time')
        ) {
            return 'timeout';
        }

        return 'execution';
    }
}
