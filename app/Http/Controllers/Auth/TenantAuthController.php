<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\OneTimeLoginToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class TenantAuthController extends Controller
{
    public function showLogin(Request $request, TenantContext $tenantContext): Response
    {
        $ip = $request->ip() ?: '127.0.0.1';
        $blockedUntil = Cache::get("login_block_ip:{$ip}");
        if ($blockedUntil && $blockedUntil <= now()->timestamp) {
            Cache::forget("login_block_ip:{$ip}");
            $blockedUntil = null;
        }

        return Inertia::render('Auth/Login', [
            'tenantName' => $tenantContext->getTenant()?->name,
            'blockedUntilTimestamp' => $blockedUntil ? (int) $blockedUntil : null,
        ]);
    }

    public function login(LoginRequest $request, TenantContext $tenantContext): HttpResponse
    {
        $ip = $request->ip() ?: '127.0.0.1';
        $blockKey = "login_block_ip:{$ip}";
        $failKey = "login_fail_ip:{$ip}";

        $blockedUntil = Cache::get($blockKey);
        if ($blockedUntil && $blockedUntil > now()->timestamp) {
            $mins = max(1, (int) ceil(($blockedUntil - now()->timestamp) / 60));

            return back()->withErrors([
                'email' => "Trop de tentatives de connexion échouées. Réessayez dans {$mins} minute(s).",
            ])->onlyInput('email');
        }

        $email = strtolower(trim($request->string('email')->toString()));
        $password = $request->string('password')->toString();

        $query = User::withoutTenantScope()->where('email', $email);
        if ($tenantContext->check()) {
            $query->where('tenant_id', $tenantContext->id());
        }

        /** @var User|null $user */
        $user = $query->first();

        if (! $user) {
            return $this->recordIpFailure($request, $ip, $failKey, $blockKey);
        }

        if ($user->isLocked()) {
            $seconds = max(1, (int) now()->diffInSeconds($user->locked_until));

            return back()->withErrors([
                'email' => __('auth.throttle', ['seconds' => $seconds]),
            ])->onlyInput('email');
        }

        if (! $user->is_active) {
            return back()->withErrors([
                'email' => __('auth.inactive'),
            ])->onlyInput('email');
        }

        if (! Hash::check($password, $user->password)) {
            $attempts = $user->failed_login_attempts + 1;
            $maxAttempts = (int) config('garagepro.security.max_login_attempts', 5);
            $lockMinutes = (int) config('garagepro.security.login_lockout_minutes', 10);

            $user->update([
                'failed_login_attempts' => $attempts,
                'locked_until' => $attempts >= $maxAttempts ? now()->addMinutes($lockMinutes) : null,
            ]);

            AuditLog::record(
                action: 'auth.login_failed',
                subject: $user,
                tenantId: $user->tenant_id,
                metadata: ['attempts' => $attempts],
                request: $request
            );

            return $this->recordIpFailure($request, $ip, $failKey, $blockKey);
        }

        Cache::forget($failKey);
        Cache::forget($blockKey);

        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();
        $request->session()->put('active_tenant_id', $user->tenant_id);

        AuditLog::record(
            action: 'auth.login_success',
            subject: $user,
            actor: $user,
            tenantId: $user->tenant_id,
            request: $request
        );

        // Si on est sur le domaine principal de production (sans sous-domaine), redirection SSO vers le sous-domaine du garage
        $host = strtolower($request->getHost());
        $isLocal = $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false || str_ends_with($host, '.local') || str_ends_with($host, '.test');
        if (! $tenantContext->check() && ! $isLocal) {
            $tenant = Tenant::find($user->tenant_id);
            if ($tenant) {
                $plainToken = OneTimeLoginToken::issueFor($tenant, $user, ttlMinutes: 5);
                $baseDomain = config('garagepro.tenant_base_domain', 'garagepro.app');
                $scheme = $request->isSecure() || app()->isProduction() ? 'https' : 'http';
                $tenantRoot = "{$scheme}://{$tenant->slug}.{$baseDomain}";

                URL::forceRootUrl($tenantRoot);
                try {
                    $signedUrl = URL::temporarySignedRoute('tenant.sso', now()->addMinutes(5), ['token' => $plainToken]);
                } finally {
                    URL::forceRootUrl(null);
                }

                return Inertia::location($signedUrl);
            }
        }

        return redirect()->route('tenant.dashboard');
    }

    private function recordIpFailure(Request $request, string $ip, string $failKey, string $blockKey): RedirectResponse
    {
        $maxAttempts = (int) config('garagepro.security.max_login_attempts', 5);
        $lockMinutes = (int) config('garagepro.security.login_lockout_minutes', 10);

        $fails = (int) Cache::get($failKey, 0) + 1;
        Cache::put($failKey, $fails, now()->addMinutes($lockMinutes));

        if ($fails >= $maxAttempts) {
            $blockedUntil = now()->addMinutes($lockMinutes)->timestamp;
            Cache::put($blockKey, $blockedUntil, now()->addMinutes($lockMinutes));
            Cache::forget($failKey);

            AuditLog::record(
                action: 'auth.ip_login_locked_10m',
                metadata: ['ip' => $ip, 'minutes' => $lockMinutes],
                request: $request
            );

            return back()->withErrors([
                'email' => "5 tentatives de connexion échouées. Votre IP est bloquée pendant {$lockMinutes} minutes.",
            ])->onlyInput('email');
        }

        return back()->withErrors([
            'email' => __('auth.failed'),
        ])->onlyInput('email');
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::guard('web')->user();

        if ($user) {
            AuditLog::record(
                action: 'auth.logout',
                subject: $user,
                actor: $user,
                tenantId: $user->tenant_id,
                request: $request
            );
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('tenant.login');
    }
}
