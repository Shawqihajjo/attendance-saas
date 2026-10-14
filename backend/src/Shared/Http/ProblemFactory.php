<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Logging\SecretRedactor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ProblemFactory
{
    /**
     * @return array<string, mixed>
     */
    public function fromThrowable(\Throwable $throwable, Request $request, bool $debug): array
    {
        $validation = $this->validationException($throwable);
        if ($validation instanceof ValidationFailedException) {
            return $this->validation($validation, $request);
        }

        if ($throwable instanceof ApiProblem) {
            $problem = $this->problem(
                $throwable->problemCode(),
                $throwable->problemTitle(),
                $throwable->getStatusCode(),
                $throwable->getMessage(),
                $request,
            );
            if ($throwable instanceof ApiProblemExtension) {
                foreach ($throwable->problemExtensions() as $key => $value) {
                    $problem[$key] = $value;
                }
            }

            return $problem;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();

            return $this->problem(
                $this->codeForStatus($status),
                $this->titleForStatus($status),
                $status,
                $status >= 500 && !$debug ? 'The server could not complete the request.' : $throwable->getMessage(),
                $request,
            );
        }

        return $this->problem(
            'server_error',
            'Server error',
            500,
            $debug ? SecretRedactor::redact($throwable->getMessage()) : 'The server could not complete the request.',
            $request,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validation(ValidationFailedException $exception, Request $request): array
    {
        $errors = [];
        foreach ($exception->getViolations() as $violation) {
            $errors[] = [
                'field' => $violation->getPropertyPath(),
                'message' => $violation->getMessage(),
            ];
        }

        $problem = $this->problem(
            'validation_failed',
            'Validation failed',
            422,
            'The request body is invalid.',
            $request,
        );
        $problem['errors'] = $errors;

        return $problem;
    }

    /**
     * @return array<string, mixed>
     */
    private function problem(string $code, string $title, int $status, string $detail, Request $request): array
    {
        $requestId = $request->attributes->get('request_id');

        return [
            'type' => '/problems/'.$code,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
            'instance' => $request->getPathInfo(),
            'requestId' => is_string($requestId) ? $requestId : null,
        ];
    }

    private function validationException(\Throwable $throwable): ?ValidationFailedException
    {
        if ($throwable instanceof ValidationFailedException) {
            return $throwable;
        }

        $previous = $throwable->getPrevious();
        if ($previous instanceof ValidationFailedException) {
            return $previous;
        }

        return null;
    }

    private function codeForStatus(int $status): string
    {
        return match ($status) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            422 => 'validation_failed',
            429 => 'rate_limited',
            default => $status >= 500 ? 'server_error' : 'http_error',
        };
    }

    private function titleForStatus(int $status): string
    {
        return match ($status) {
            401 => 'Authentication required',
            403 => 'Forbidden',
            404 => 'Not found',
            405 => 'Method not allowed',
            422 => 'Validation failed',
            429 => 'Too many attempts',
            default => $status >= 500 ? 'Server error' : 'Request error',
        };
    }
}
