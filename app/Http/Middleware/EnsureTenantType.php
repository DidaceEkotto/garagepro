<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sépare les routes métiers selon le type d'établissement (garage, centre_technique, assurance).
 */
class EnsureTenantType
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next, string ...$allowedTypes): Response
    {
        $tenant = $this->tenantContext->getTenant();
        $currentType = $tenant?->type ?: Tenant::TYPE_GARAGE;

        if (! empty($allowedTypes) && ! in_array($currentType, $allowedTypes, true)) {
            return redirect('/tableau-de-bord')->with(
                'warning',
                'Ce module est réservé à un autre type d\'établissement.'
            );
        }

        return $next($request);
    }
}
