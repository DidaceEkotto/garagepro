<?php

namespace App\Http\Middleware;

use App\Services\QuoteAutoReminderService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware terminable : déclenche automatiquement les relances J+2 de devis
 * après l'envoi de la réponse HTTP (zéro latence utilisateur, sans cron ni job).
 * Un verrou de cache garantit au plus une vérification toutes les 15 minutes.
 */
class RunAutoQuoteReminders
{
    public const LOCK_KEY = 'quotes:auto_remind_j2_lock';

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->isMethod('GET')) {
            return;
        }

        if (app()->runningUnitTests() && ! config('quotes.auto_remind_in_tests', false)) {
            return;
        }

        // Verrou atomique : exécuté au plus une fois toutes les 15 minutes
        if (! Cache::add(self::LOCK_KEY, true, now()->addMinutes(15))) {
            return;
        }

        try {
            app(QuoteAutoReminderService::class)->dispatchDueReminders(10);
        } catch (\Throwable $e) {
            Log::warning('RunAutoQuoteReminders failed (non-blocking)', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
