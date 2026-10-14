<?php

declare(strict_types=1);

namespace App\Diagnostics;

final readonly class DiagnosticCheck
{
    public function __construct(
        public string $status,
        public ?string $detail = null,
    ) {
    }

    /**
     * @return array{status: string, detail?: string}
     */
    public function toArray(): array
    {
        $payload = ['status' => $this->status];
        if ($this->detail !== null) {
            $payload['detail'] = $this->detail;
        }

        return $payload;
    }
}
