<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passe le tenant en mode lecture seule lorsque son essai ou abonnement est expiré.
 * Les données ne sont jamais supprimées.
 */
class EnsureTenantWritable
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenantContext->getTenant();

        if ($tenant && $tenant->isReadOnly() && ! $request->isMethodSafe()) {
            $message = 'Votre période d\'essai ou abonnement a expiré. Votre compte est en lecture seule jusqu\'au renouvellement.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
            }

            return back()->with('error', $message);
        }

        return $next($request);
    }
}
