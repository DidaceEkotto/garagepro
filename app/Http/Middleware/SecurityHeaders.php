<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ajoute les en-têtes de sécurité HTTP recommandés sur toutes les réponses.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(self)');
        $response->headers->set('X-XSS-Protection', '0');

        if ($request->isSecure() || app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $viteDev = app()->isProduction()
            ? ''
            : ' http://localhost:5173 http://127.0.0.1:5173 http://[::1]:5173';
        $viteWs = app()->isProduction()
            ? ''
            : ' ws://localhost:5173 ws://127.0.0.1:5173 ws://[::1]:5173';

        // CSP compatible Inertia/Vue 3, Bootstrap 5 et Livewire 4.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://challenges.cloudflare.com https://js.hcaptcha.com https://cdn.jsdelivr.net https://code.jquery.com{$viteDev}",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net{$viteDev}",
            "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net",
            "img-src 'self' data: blob:",
            "connect-src 'self' https://api.saspay.me{$viteDev}{$viteWs}",
            "frame-src 'self' https://challenges.cloudflare.com https://newassets.hcaptcha.com",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
