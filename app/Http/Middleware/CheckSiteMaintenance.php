<?php

namespace App\Http\Middleware;

use App\Models\PlatformSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque l'accès à l'ensemble du site lorsque le mode maintenance est activé
 * dans l'Admin, tout en laissant l'espace /admin et Livewire totalement accessibles.
 */
class CheckSiteMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->is('admin', 'admin/*', 'up', 'livewire*', 'storage/*')
            || $request->hasHeader('X-Livewire')
            || str_contains((string) $request->header('Referer'), '/admin')
            || Auth::guard('admin')->check()
        ) {
            return $next($request);
        }

        try {
            $isMaintenance = PlatformSetting::isMaintenanceMode();
            $message = PlatformSetting::maintenanceMessage();
        } catch (\Throwable) {
            return $next($request);
        }

        if ($isMaintenance) {
            return response()->view('errors.503', [
                'maintenanceMessage' => $message,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $next($request);
    }
}
