<?php

declare(strict_types=1);

namespace App\Integration;

final class StreamWebhookClient implements WebhookClient
{
    public function post(string $url, string $body): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 5,
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
            ],
        ]);
        $http_response_header = [];
        $result = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match) === 1) {
            $status = (int) $match[1];
        }
        if ($result === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Webhook delivery failed.');
        }
    }
}
