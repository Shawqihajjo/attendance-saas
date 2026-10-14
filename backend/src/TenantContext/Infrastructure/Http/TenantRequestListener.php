<?php

declare(strict_types=1);

namespace App\TenantContext\Infrastructure\Http;

use App\TenantContext\Application\TenantResolver;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use App\TenantContext\Domain\TenantMismatchException;
use App\TenantContext\Domain\TenantNotFoundException;
use App\TenantContext\Domain\TenantNotProvisionedException;
use App\TenantContext\Domain\TenantUnavailableException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

#[AsEventListener(event: RequestEvent::class, method: 'onRequest', priority: 10)]
#[AsEventListener(event: FinishRequestEvent::class, method: 'onFinish', priority: -100)]
final class TenantRequestListener
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantDatabaseSwitcher $switcher,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/api/v1/tenant')) {
            return;
        }

        try {
            $this->switcher->switchTo($this->resolver->resolve($event->getRequest())->tenantId);
        } catch (TenantNotFoundException $exception) {
            throw new TenantHostUnknownException($exception);
        } catch (TenantMismatchException $exception) {
            throw new TenantRequestRejectedException('tenant_mismatch', 'The tenant claim does not match the host.', $exception);
        } catch (TenantUnavailableException|TenantNotProvisionedException $exception) {
            throw new TenantRequestRejectedException('tenant_unavailable', 'The tenant is not available.', $exception);
        }
    }

    public function onFinish(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->switcher->reset();
        }
    }
}
