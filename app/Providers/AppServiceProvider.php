<?php

namespace App\Providers;

use App\Services\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, fn () => new TenantContext());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Configure les limiteurs de débit anti-DDoS et anti-abus.
     */
    protected function configureRateLimiting(): void
    {
        $tooManyAttempts = function (string $message) {
            return function (Request $request, array $headers) use ($message) {
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json(['message' => $message], 429, $headers);
                }

                return response($message, 429, $headers);
            };
        };

        RateLimiter::for('login', function (Request $request) use ($tooManyAttempts) {
            $email = strtolower((string) $request->input('email', ''));

            return Limit::perMinute(5)
                ->by($email.'|'.$request->ip())
                ->response($tooManyAttempts('Trop de tentatives de connexion. Veuillez patienter avant de réessayer.'));
        });

        RateLimiter::for('register', function (Request $request) use ($tooManyAttempts) {
            return Limit::perHour(5)
                ->by($request->ip())
                ->response($tooManyAttempts('Trop de tentatives d\'inscription depuis cette adresse IP.'));
        });

        RateLimiter::for('password-reset', function (Request $request) use ($tooManyAttempts) {
            $email = strtolower((string) $request->input('email', ''));

            return Limit::perMinutes(15, 3)
                ->by($email.'|'.$request->ip())
                ->response($tooManyAttempts('Trop de demandes de réinitialisation. Veuillez patienter.'));
        });

        RateLimiter::for('webhook', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(60)
                ->by($request->ip())
                ->response($tooManyAttempts('Trop de requêtes webhook.'));
        });

        RateLimiter::for('api', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response($tooManyAttempts('Limite de requêtes atteinte.'));
        });

        RateLimiter::for('search', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response($tooManyAttempts('Trop de recherches effectuées. Patientez quelques secondes.'));
        });

        RateLimiter::for('pdf', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(15)
                ->by($request->user()?->id ?: $request->ip())
                ->response($tooManyAttempts('Trop de générations de PDF demandées. Patientez un instant.'));
        });
    }
}
