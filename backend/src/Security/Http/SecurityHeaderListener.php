<?php

declare(strict_types=1);

namespace App\Security\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class SecurityHeaderListener
{
    #[AsEventListener(event: ResponseEvent::class, priority: -20)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
        if (!$headers->has('Cache-Control')) {
            $headers->set('Cache-Control', 'no-store');
        }
        if ($event->getRequest()->isSecure() && !$headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
    }
}
