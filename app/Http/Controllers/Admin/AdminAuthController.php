<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    public function showLogin(): View
    {
        return view('admin.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $ip = $request->ip() ?: '127.0.0.1';
        $blockKey = "admin_login_block_ip:{$ip}";
        $failKey = "admin_login_fail_ip:{$ip}";

        $blockedUntil = Cache::get($blockKey);
        if ($blockedUntil && $blockedUntil > now()->timestamp) {
            $mins = max(1, (int) ceil(($blockedUntil - now()->timestamp) / 60));

            return back()->withErrors([
                'email' => "Trop de tentatives échouées. Accès bloqué pendant {$mins} minute(s).",
            ])->onlyInput('email');
        }

        $email = strtolower($request->string('email')->toString());
        $password = $request->string('password')->toString();

        /** @var Admin|null $admin */
        $admin = Admin::where('email', $email)->first();

        if (! $admin || ! $admin->is_active) {
            return $this->recordAdminIpFailure($request, $ip, $failKey, $blockKey);
        }

        if ($admin->isLocked()) {
            return back()->withErrors([
                'email' => 'Compte administrateur temporairement verrouillé.',
            ])->onlyInput('email');
        }

        if (! Hash::check($password, $admin->password)) {
            $attempts = $admin->failed_login_attempts + 1;
            $maxAttempts = (int) config('garagepro.security.max_login_attempts', 5);
            $lockMinutes = (int) config('garagepro.security.login_lockout_minutes', 15);

            $admin->update([
                'failed_login_attempts' => $attempts,
                'locked_until' => $attempts >= $maxAttempts ? now()->addMinutes($lockMinutes) : null,
            ]);

            AuditLog::record(
                action: 'admin.login_failed',
                subject: $admin,
                metadata: ['attempts' => $attempts],
                request: $request
            );

            return $this->recordAdminIpFailure($request, $ip, $failKey, $blockKey);
        }

        Cache::forget($failKey);
        Cache::forget($blockKey);

        $admin->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        Auth::guard('admin')->login($admin, $request->boolean('remember'));
        $request->session()->regenerate();

        AuditLog::record(
            action: 'admin.login_success',
            subject: $admin,
            actor: $admin,
            request: $request
        );

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();

        if ($admin) {
            AuditLog::record(
                action: 'admin.logout',
                subject: $admin,
                actor: $admin,
                request: $request
            );
        }

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function recordAdminIpFailure(Request $request, string $ip, string $failKey, string $blockKey): RedirectResponse
    {
        $fails = (int) Cache::get($failKey, 0) + 1;
        Cache::put($failKey, $fails, now()->addMinutes(10));

        if ($fails >= 5) {
            Cache::put($blockKey, now()->addMinutes(10)->timestamp, now()->addMinutes(10));
            Cache::forget($failKey);

            AuditLog::record(
                action: 'admin.ip_login_locked',
                metadata: ['ip' => $ip, 'minutes' => 10],
                request: $request
            );

            return back()->withErrors([
                'email' => 'Trop de tentatives échouées. Votre IP est bloquée pendant 10 minutes.',
            ])->onlyInput('email');
        }

        return back()->withErrors([
            'email' => 'Identifiants administrateur invalides.',
        ])->onlyInput('email');
    }
}
