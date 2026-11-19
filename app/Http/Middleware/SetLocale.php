<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('garagepro.supported_locales', ['fr' => []]));

        $locale = $request->session()->get('locale')
            ?? $request->cookie('locale')
            ?? config('app.locale', 'fr');

        if (! is_string($locale) || ! in_array($locale, $supported, true)) {
            $locale = 'fr';
        }

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
