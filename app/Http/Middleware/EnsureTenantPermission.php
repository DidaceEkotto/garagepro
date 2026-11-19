<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware robuste vérifiant que l'utilisateur connecté possède au moins
 * une des permissions requises pour accéder à la ressource du tenant.
 */
class EnsureTenantPermission
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user('web');
        if (! $user) {
            return redirect()->route('tenant.login');
        }

        if ($this->tenantContext->check()) {
            setPermissionsTeamId($this->tenantContext->id());
        }

        $required = array_filter(array_map('trim', explode('|', $permissions)));
        if (! empty($required) && ! $user->hasAnyPermission($required)) {
            abort(403, 'Vous n\'avez pas les autorisations nécessaires pour accéder à cette ressource.');
        }

        return $next($request);
    }
}
