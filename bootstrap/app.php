<?php

use App\Http\Middleware\BlockSuspiciousIp;
use App\Http\Middleware\CheckSiteMaintenance;
use App\Http\Middleware\EnsureTenantPermission;
use App\Http\Middleware\EnsureTenantType;
use App\Http\Middleware\EnsureTenantUser;
use App\Http\Middleware\EnsureTenantWritable;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleOfflineReplayIdempotency;
use App\Http\Middleware\ResolveTenantFromSubdomain;
use App\Http\Middleware\RunAutoQuoteReminders;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = env('TRUSTED_PROXIES');
        if ($trustedProxies) {
            $middleware->trustProxies(at: explode(',', $trustedProxies));
        }

        // Blocage IP suspectes en tête de pile + en-têtes de sécurité globaux.
        $middleware->prepend(BlockSuspiciousIp::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            SetLocale::class,
            CheckSiteMaintenance::class,
            ResolveTenantFromSubdomain::class,
            HandleOfflineReplayIdempotency::class,
            HandleInertiaRequests::class,
            RunAutoQuoteReminders::class,
        ]);

        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ResolveTenantFromSubdomain::class
        );

        $middleware->alias([
            'tenant.auth' => EnsureTenantUser::class,
            'tenant.writable' => EnsureTenantWritable::class,
            'tenant.permission' => EnsureTenantPermission::class,
            'tenant.type' => EnsureTenantType::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/saspay',
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('admin') || $request->is('admin/*')) {
                return route('admin.login');
            }

            return route('tenant.login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
