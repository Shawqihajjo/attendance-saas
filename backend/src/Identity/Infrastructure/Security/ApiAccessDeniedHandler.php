<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Http\ProblemHttpResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

final class ApiAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        $requestId = $request->attributes->get('request_id');

        return new ProblemHttpResponse([
            'type' => '/problems/forbidden',
            'title' => 'Forbidden',
            'status' => Response::HTTP_FORBIDDEN,
            'detail' => 'You do not have access to this resource.',
            'code' => 'forbidden',
            'instance' => $request->getPathInfo(),
            'requestId' => is_string($requestId) ? $requestId : null,
        ]);
    }
}
