<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

final class ProblemHttpResponse extends JsonResponse
{
    /**
     * @param array<string, mixed> $problem
     */
    public function __construct(array $problem)
    {
        $status = $problem['status'] ?? 500;
        parent::__construct($problem, is_int($status) ? $status : 500);
        $this->headers->set('Content-Type', 'application/problem+json');
        $this->headers->set('Cache-Control', 'no-store');
    }
}
