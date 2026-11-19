<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\BlockedIp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware placé en tête de pile pour bloquer les IP suspectes,
 * contrer les attaques DDoS (requêtes anormales/sec) et bloquer les injections SQL / XSS (20 min).
 */
class BlockSuspiciousIp
{
    private const SUSPICIOUS_PATHS = [
        '/.env',
        '/.git',
        '/wp-admin',
        '/wp-login.php',
        '/wp-content',
        '/phpmyadmin',
        '/pma',
        '/adminer',
        '/xmlrpc.php',
        '/config.php',
        '/server-status',
    ];

    private const INJECTION_PATTERNS = [
        '/union\s+(all\s+)?select/i',
        '/\b(or|and)\s+1\s*=\s*1\b/i',
        '/drop\s+table\b/i',
        '/<script[\s>]/i',
        '/javascript\s*:/i',
        '/on(error|load|click|mouseover)\s*=/i',
        '/<iframe[\s>]/i',
        '/\.\.\/\.\.\//',
        '/\/etc\/passwd/i',
        '/benchmark\s*\(/i',
        '/sleep\s*\(\s*\d+/i',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        if (! $ip) {
            return $next($request);
        }

        if (BlockedIp::isWhitelisted($ip)) {
            return $next($request);
        }

        if (BlockedIp::isCurrentlyBlocked($ip)) {
            return $this->denyResponse($request);
        }

        // Protection anti-DDoS / trafic anormal par seconde (sauf assets statiques)
        if ($this->isAbnormalBurst($request, $ip)) {
            $minutes = (int) config('garagepro.security.auto_block_minutes', 20);
            BlockedIp::blockIp($ip, 'Trafic anormal / tentative DDoS détectée', minutes: $minutes);
            AuditLog::record(
                action: 'security.ddos_ip_blocked',
                metadata: ['ip' => $ip, 'path' => $request->path(), 'minutes' => $minutes],
                request: $request
            );

            return $this->denyResponse($request);
        }

        if ($reason = $this->detectMaliciousProbe($request)) {
            $minutes = (int) config('garagepro.security.auto_block_minutes', 20);
            BlockedIp::blockIp($ip, $reason, minutes: $minutes);
            AuditLog::record(
                action: 'security.ip_auto_blocked',
                metadata: [
                    'reason' => $reason,
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'minutes' => $minutes,
                ],
                request: $request
            );

            return $this->denyResponse($request);
        }

        return $next($request);
    }

    private function isAbnormalBurst(Request $request, string $ip): bool
    {
        $path = $request->path();
        if (str_starts_with($path, 'build/') || str_starts_with($path, 'images/')) {
            return false;
        }

        $maxPer5Sec = (int) config('garagepro.security.ddos_max_requests_per_5s', 45);
        $key = 'ddos_burst:'.$ip;
        $count = Cache::get($key, 0) + 1;
        Cache::put($key, $count, 5);

        return $count > $maxPer5Sec;
    }

    private function detectMaliciousProbe(Request $request): ?string
    {
        $path = '/'.ltrim(strtolower($request->path()), '/');

        foreach (self::SUSPICIOUS_PATHS as $suspiciousPath) {
            if ($path === $suspiciousPath || str_starts_with($path, $suspiciousPath.'/')) {
                return "Scan de chemin sensible ({$suspiciousPath})";
            }
        }

        $rawTarget = urldecode($request->getRequestUri());
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $rawTarget) === 1) {
                return 'Tentative d\'injection SQL/XSS détectée dans l\'URL';
            }
        }

        // Inspection des champs de formulaire (hors mots de passe et jetons)
        $inputs = $request->except(['password', 'password_confirmation', 'current_password', 'new_password', '_token']);
        if (! empty($inputs)) {
            $flat = json_encode($inputs, JSON_UNESCAPED_UNICODE) ?: '';
            foreach (self::INJECTION_PATTERNS as $pattern) {
                if (preg_match($pattern, $flat) === 1) {
                    return 'Tentative d\'injection SQL/XSS détectée dans les données';
                }
            }
        }

        return null;
    }

    private function denyResponse(Request $request): Response
    {
        $minutes = (int) config('garagepro.security.auto_block_minutes', 20);
        $message = "Votre adresse IP a été temporairement bloquée pour des raisons de sécurité (déblocage automatique après {$minutes} minutes).";

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        return response($message, Response::HTTP_FORBIDDEN)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
