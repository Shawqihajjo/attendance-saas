<?php

declare(strict_types=1);

namespace App\Security\Http;

use App\Shared\Http\ProblemHttpResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Cookie sessions use SameSite=Lax. A browser Origin that names another host is rejected.
 * Cross-origin responses are limited to the host that received the request.
 */
final class BrowserOriginListener
{
    #[AsEventListener(event: RequestEvent::class, priority: 40)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->api($event->getRequest())) {
            return;
        }
        $request = $event->getRequest();
        $origin = $request->headers->get('Origin');
        $allowed = $this->allowedOrigin($request, $origin);
        if ($request->getMethod() === 'OPTIONS') {
            if (is_string($origin) && $origin !== '' && $allowed === null) {
                $event->setResponse(new Response('', 403));

                return;
            }
            $response = new Response('', 204);
            $this->apply($response, $allowed);
            $event->setResponse($response);

            return;
        }
        if ($allowed === null && $this->mutation($request) && is_string($origin) && $origin !== '' && $origin !== 'null') {
            $requestId = $request->attributes->get('request_id');
            $event->setResponse(new ProblemHttpResponse([
                'type' => '/problems/csrf_rejected',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'The request origin does not match this host.',
                'code' => 'csrf_rejected',
                'instance' => $request->getPathInfo(),
                'requestId' => is_string($requestId) ? $requestId : null,
            ]));
        }
    }

    #[AsEventListener(event: ResponseEvent::class, priority: -5)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->api($event->getRequest())) {
            return;
        }
        $this->apply($event->getResponse(), $this->allowedOrigin($event->getRequest(), $event->getRequest()->headers->get('Origin')));
    }

    private function apply(Response $response, ?string $origin): void
    {
        $existing = $response->headers->get('Vary');
        $parts = array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            explode(',', is_string($existing) ? $existing : ''),
        )));
        if (!in_array('Origin', $parts, true)) {
            $parts[] = 'Origin';
        }
        $response->headers->set('Vary', implode(', ', $parts));
        if ($origin === null) {
            return;
        }
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Request-Id, Idempotency-Key');
        $response->headers->set('Access-Control-Max-Age', '600');
    }

    private function allowedOrigin(Request $request, mixed $origin): ?string
    {
        if (!is_string($origin) || $origin === '' || $origin === 'null') {
            return null;
        }
        $parts = parse_url($origin);
        if ($parts === false) {
            return null;
        }
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        if (!is_string($scheme) || !is_string($host) || !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if (strcasecmp($host, $request->getHost()) !== 0) {
            return null;
        }
        if ($request->isSecure() && $scheme !== 'https') {
            return null;
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port !== $request->getPort()) {
            return null;
        }

        return $origin;
    }

    private function api(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/');
    }

    private function mutation(Request $request): bool
    {
        return in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}
