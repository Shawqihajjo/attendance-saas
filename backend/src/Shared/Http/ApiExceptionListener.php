<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Logging\SecretRedactor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[AsEventListener(event: ExceptionEvent::class, priority: -10)]
final class ApiExceptionListener
{
    public function __construct(
        private readonly ProblemFactory $problems,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if ($event->hasResponse()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();
        if (!$throwable instanceof HttpExceptionInterface || $throwable->getStatusCode() >= 500) {
            $this->logger->error(SecretRedactor::redact($throwable->getMessage()), [
                'exception_class' => $throwable::class,
            ]);
        }

        $event->setResponse(new ProblemHttpResponse(
            $this->problems->fromThrowable($throwable, $request, $this->debug),
        ));
    }
}
