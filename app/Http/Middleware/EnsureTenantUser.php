<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérifie que la requête appartient bien à un sous-domaine tenant valide
 * et que l'utilisateur connecté appartient à ce tenant.
 */
class EnsureTenantUser
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenantContext->check()) {
            abort(404, 'Sous-domaine garage requis.');
        }

        $user = Auth::guard('web')->user();
        if (! $user || (int) $user->tenant_id !== (int) $this->tenantContext->id()) {
            return redirect()->route('tenant.login');
        }

        if (! $user->is_active) {
            Auth::guard('web')->logout();

            return redirect()->route('tenant.login')->withErrors([
                'email' => 'Votre compte utilisateur est désactivé.',
            ]);
        }

        return $next($request);
    }
}
