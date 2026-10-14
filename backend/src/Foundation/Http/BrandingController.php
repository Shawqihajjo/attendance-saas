<?php

declare(strict_types=1);

namespace App\Foundation\Http;

use App\Platform\TenantRegistry\Application\ReadTenantConfiguration;
use App\Shared\Http\ApiResponse;
use App\TenantContext\Domain\TenantMismatchException;
use App\TenantContext\Domain\TenantNotFoundException;
use App\TenantContext\Domain\TenantUnavailableException;
use App\TenantContext\Infrastructure\Http\TenantHostUnknownException;
use App\TenantContext\Infrastructure\Http\TenantRequestRejectedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class BrandingController
{
    public function __construct(private readonly ReadTenantConfiguration $configurations)
    {
    }

    #[Route('/api/v1/public/branding', name: 'public_branding', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $configuration = $this->configurations->forHost($request);
        } catch (TenantNotFoundException $exception) {
            throw new TenantHostUnknownException($exception);
        } catch (TenantMismatchException $exception) {
            throw new TenantRequestRejectedException('tenant_mismatch', 'The tenant claim does not match the host.', $exception);
        } catch (TenantUnavailableException $exception) {
            throw new TenantRequestRejectedException('tenant_unavailable', 'The tenant is not available.', $exception);
        }

        $response = ApiResponse::ok($configuration->toArray());
        $response->headers->set('Cache-Control', 'private, max-age=0, must-revalidate');
        $response->headers->set('Vary', 'Host');

        return $response;
    }
}
