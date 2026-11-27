<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assure l'idempotence universelle (anti-doublon) pour toute requête rejouée
 * depuis le Mode Hors Ligne (header X-Offline-Replay: 1 + X-Client-Uuid).
 */
class HandleOfflineReplayIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->header('X-Offline-Replay') !== '1'
            || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
        ) {
            return $next($request);
        }

        $clientUuid = trim((string) $request->header('X-Client-Uuid', ''));
        if ($clientUuid === '' || strlen($clientUuid) > 64) {
            return $next($request);
        }

        $tenantId = app(TenantContext::class)->id() ?? $request->user('web')?->tenant_id;
        if (! $tenantId) {
            return $next($request);
        }

        $alreadyProcessed = DB::table('offline_replay_keys')
            ->where('tenant_id', $tenantId)
            ->where('client_uuid', $clientUuid)
            ->exists();

        if ($alreadyProcessed) {
            return response()->json([
                'ok' => true,
                'duplicate' => true,
                'message' => 'Opération hors ligne déjà synchronisée (anti-doublon).',
            ], 200);
        }

        $response = $next($request);

        $status = $response->getStatusCode();
        $hasValidationErrors = $request->hasSession() && $request->session()->has('errors');

        if ($status >= 200 && $status < 400 && ! $hasValidationErrors) {
            DB::table('offline_replay_keys')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'client_uuid' => $clientUuid,
                'method' => $request->method(),
                'path' => substr('/'.ltrim($request->path(), '/'), 0, 255),
                'status_code' => $status,
                'created_at' => now(),
            ]);

            // Purge quotidienne automatique des clés d'idempotence de plus de 30 jours
            if (Cache::add('offline_replay_prune_lock', true, now()->addDay())) {
                DB::table('offline_replay_keys')
                    ->where('created_at', '<', now()->subDays(30))
                    ->delete();
            }
        }

        return $response;
    }
}
