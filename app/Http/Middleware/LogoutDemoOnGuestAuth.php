<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si l'utilisateur est connecté sur le compte Sandbox Démo (demo-douala) et clique
 * vers /connexion ou /inscription, on le déconnecte automatiquement de la démo
 * afin qu'il soit redirigé sans blocage vers la page demandée.
 */
class LogoutDemoOnGuestAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user) {
            $isDemoSession = $request->hasSession() && $request->session()->has('demo_tenant_id');
            if (! $isDemoSession && $user->tenant_id) {
                $tenantSlug = Tenant::whereKey($user->tenant_id)->value('slug');
                $isDemoSession = ($tenantSlug === 'demo-douala');
            }

            if ($isDemoSession) {
                Auth::guard('web')->logout();
                if ($request->hasSession()) {
                    $request->session()->forget(['demo_tenant_id', 'active_tenant_id', 'active_agency_id']);
                    $request->session()->regenerateToken();
                }
                app(TenantContext::class)->clear();
            }
        }

        return $next($request);
    }
}
