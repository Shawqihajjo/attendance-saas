<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Http\ProblemHttpResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $requestId = $request->attributes->get('request_id');

        return new ProblemHttpResponse([
            'type' => '/problems/unauthenticated',
            'title' => 'Authentication required',
            'status' => Response::HTTP_UNAUTHORIZED,
            'detail' => 'Sign in is required for this resource.',
            'code' => 'unauthenticated',
            'instance' => $request->getPathInfo(),
            'requestId' => is_string($requestId) ? $requestId : null,
        ]);
    }
}
