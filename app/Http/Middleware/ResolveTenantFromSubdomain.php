<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Résout le tenant actif à partir du sous-domaine de la requête.
 */
class ResolveTenantFromSubdomain
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->extractSubdomain($request->getHost());

        if ($slug === null || in_array($slug, config('garagepro.reserved_subdomains', []), true)) {
            $user = Auth::guard('web')->user();
            $sessionTenantId = $request->hasSession()
                ? ($request->session()->get('active_tenant_id') ?? $request->session()->get('demo_tenant_id'))
                : null;

            // En environnement local sans sous-domaine (ex: 127.0.0.1:5675 ou localhost),
            // on résout le tenant de l'utilisateur authentifié (en vérifiant la correspondance si active_tenant_id est en session).
            if ($user && $this->isLocalOrIpHost($request->getHost()) && (! $sessionTenantId || (int) $user->tenant_id === (int) $sessionTenantId)) {
                $localTenant = Tenant::find($user->tenant_id);
                if ($localTenant && $localTenant->status !== Tenant::STATUS_SUSPENDED) {
                    $this->tenantContext->setTenant($localTenant);

                    return $next($request);
                }
            }

            $this->tenantContext->clear();

            return $next($request);
        }

        $tenant = Tenant::where('slug', $slug)->first();

        if (! $tenant) {
            abort(404, 'Ce garage est introuvable.');
        }

        if ($tenant->status === Tenant::STATUS_SUSPENDED) {
            abort(403, 'Ce compte garage est actuellement suspendu. Contactez le support.');
        }

        $this->tenantContext->setTenant($tenant);

        // Sécurité : empêche un utilisateur connecté sur un autre tenant d'accéder à ce sous-domaine.
        $user = Auth::guard('web')->user();
        if ($user && (int) $user->tenant_id !== (int) $tenant->id) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            abort(403, 'Accès refusé : vous n\'appartenez pas à ce garage.');
        }

        return $next($request);
    }

    private function extractSubdomain(string $host): ?string
    {
        $baseDomain = strtolower((string) config('garagepro.tenant_base_domain', 'garagepro.app'));
        $host = strtolower($host);

        if ($host === $baseDomain || $host === 'localhost' || $host === '127.0.0.1') {
            return null;
        }

        $suffix = '.'.$baseDomain;
        if (str_ends_with($host, $suffix)) {
            $sub = substr($host, 0, -strlen($suffix));

            return $sub !== '' && ! str_contains($sub, '.') ? $sub : null;
        }

        return null;
    }

    private function isLocalOrIpHost(string $host): bool
    {
        return $host === 'localhost'
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }
}
