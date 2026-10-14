<?php

declare(strict_types=1);

namespace App\Diagnostics;

final readonly class DiagnosticReport
{
    /**
     * @param array<string, DiagnosticCheck> $checks
     */
    public function __construct(
        public string $status,
        public array $checks,
    ) {
    }

    /**
     * @return array{status: string, checks: array<string, array{status: string, detail?: string}>}
     */
    public function toArray(): array
    {
        $checks = [];
        foreach ($this->checks as $name => $check) {
            $checks[$name] = $check->toArray();
        }

        return [
            'status' => $this->status,
            'checks' => $checks,
        ];
    }
}
