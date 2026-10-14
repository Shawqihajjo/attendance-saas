<?php

declare(strict_types=1);

namespace App\Integration;

interface WebhookClient
{
    public function post(string $url, string $body): void;
}
