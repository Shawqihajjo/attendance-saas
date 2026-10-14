<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Uid\Uuid;

final class RequestIdListener
{
    #[AsEventListener(event: RequestEvent::class, priority: 100)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $incoming = $event->getRequest()->headers->get('X-Request-Id');
        $requestId = is_string($incoming) && preg_match('/^[A-Za-z0-9-]{8,80}$/', $incoming) === 1
            ? $incoming
            : Uuid::v7()->toRfc4122();
        $event->getRequest()->attributes->set('request_id', $requestId);
    }

    #[AsEventListener(event: ResponseEvent::class)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requestId = $event->getRequest()->attributes->get('request_id');
        if (is_string($requestId) && $requestId !== '') {
            $event->getResponse()->headers->set('X-Request-Id', $requestId);
        }
    }
}
