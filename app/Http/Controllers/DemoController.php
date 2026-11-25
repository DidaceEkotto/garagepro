<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * Permet de tester l'ensemble de MboaCar en mode sandbox sans inscription.
 */
class DemoController extends Controller
{
    public function enter(Request $request): RedirectResponse
    {
        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        $tenant = Tenant::where('slug', 'demo-douala')->firstOrFail();
        $user = User::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('email', 'gerant-demo@garagepro.app')
            ->firstOrFail();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('demo_tenant_id', $tenant->id);

        return redirect()->route('tenant.dashboard')
            ->with('success', 'Mode Sandbox Démo activé ! Explorez toutes les fonctionnalités librement.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $tenant = Tenant::where('slug', 'demo-douala')->first();
        if ($tenant) {
            $tenant->forceDelete();
        }

        return $this->enter($request);
    }

    public function exitAndRedirect(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->forget(['demo_tenant_id', 'active_tenant_id', 'active_agency_id']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $target = $request->query('to', 'register');

        return match ($target) {
            'login', '/connexion' => redirect()->route('tenant.login'),
            'home', '/' => redirect()->route('home'),
            default => redirect()->route('register'),
        };
    }
}
