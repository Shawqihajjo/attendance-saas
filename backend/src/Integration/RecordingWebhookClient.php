<?php

declare(strict_types=1);

namespace App\Integration;

final class RecordingWebhookClient implements WebhookClient
{
    public bool $fail = false;

    /** @var list<string> */
    public array $bodies = [];

    public function post(string $url, string $body): void
    {
        if ($this->fail) {
            throw new \RuntimeException('Webhook delivery failed.');
        }
        $this->bodies[] = $body;
    }

    public function clear(): void
    {
        $this->bodies = [];
    }

    public function latestBody(): string
    {
        $body = $this->bodies[array_key_last($this->bodies) ?? -1] ?? null;
        if (!is_string($body)) {
            throw new \RuntimeException('No webhook body was recorded.');
        }

        return $body;
    }
}
