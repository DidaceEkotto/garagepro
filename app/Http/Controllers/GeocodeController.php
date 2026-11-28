<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Proxy de géocodage 100% gratuit basé sur OpenStreetMap Nominatim (+ cache 24h).
 * Évite les problèmes CORS/User-Agent et protège contre les abus via throttle.
 */
class GeocodeController extends Controller
{
    private const COUNTRY_CODES = [
        'cameroun' => 'cm', 'cameroon' => 'cm',
        'côte d\'ivoire' => 'ci', 'cote d\'ivoire' => 'ci', 'ivory coast' => 'ci',
        'sénégal' => 'sn', 'senegal' => 'sn',
        'gabon' => 'ga',
        'congo' => 'cg', 'république du congo' => 'cg',
        'rdc' => 'cd', 'république démocratique du congo' => 'cd', 'congo-kinshasa' => 'cd',
        'bénin' => 'bj', 'benin' => 'bj',
        'togo' => 'tg',
        'mali' => 'ml',
        'burkina faso' => 'bf',
        'niger' => 'ne',
        'tchad' => 'td', 'chad' => 'td',
        'centrafrique' => 'cf', 'république centrafricaine' => 'cf',
        'guinée' => 'gn', 'guinee' => 'gn', 'guinea' => 'gn',
        'maroc' => 'ma', 'morocco' => 'ma',
        'algérie' => 'dz', 'algerie' => 'dz', 'algeria' => 'dz',
        'tunisie' => 'tn', 'tunisia' => 'tn',
        'madagascar' => 'mg',
        'nigeria' => 'ng',
        'ghana' => 'gh',
        'kenya' => 'ke',
        'rwanda' => 'rw',
        'afrique du sud' => 'za', 'south africa' => 'za',
        'france' => 'fr',
        'belgique' => 'be',
    ];

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $country = trim((string) $request->query('country', ''));

        if (mb_strlen($q) < 2 || mb_strlen($q) > 120) {
            return response()->json(['results' => []]);
        }

        $cc = $this->resolveCountryCode($country);
        $cacheKey = 'osm_geo_search:'.sha1(mb_strtolower($q).'|'.$cc);

        $results = Cache::remember($cacheKey, now()->addHours(24), function () use ($q, $cc): array {
            try {
                $params = [
                    'q' => $q,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => 6,
                    'accept-language' => 'fr',
                ];
                if ($cc !== '') {
                    $params['countrycodes'] = $cc;
                }

                $resp = Http::timeout(6)
                    ->withHeaders(['User-Agent' => 'MboaCar-SaaS/2.0 (contact@mboacar.com)'])
                    ->get('https://nominatim.openstreetmap.org/search', $params);

                if (! $resp->successful()) {
                    return [];
                }

                return collect($resp->json() ?? [])->map(function ($item) {
                    $addr = $item['address'] ?? [];
                    $city = $addr['city']
                        ?? $addr['town']
                        ?? $addr['village']
                        ?? $addr['municipality']
                        ?? $addr['suburb']
                        ?? $addr['state']
                        ?? '';
                    $suburb = $addr['suburb'] ?? $addr['neighbourhood'] ?? $addr['quarter'] ?? '';

                    return [
                        'display_name' => (string) ($item['display_name'] ?? ''),
                        'short_label' => trim(($suburb && $suburb !== $city ? "{$suburb}, " : '').($city ?: ($item['name'] ?? ''))),
                        'city' => (string) ($city ?: ($item['name'] ?? '')),
                        'country' => (string) ($addr['country'] ?? ''),
                        'lat' => round((float) ($item['lat'] ?? 0), 7),
                        'lon' => round((float) ($item['lon'] ?? 0), 7),
                    ];
                })->filter(fn ($r) => $r['display_name'] !== '' && ($r['lat'] != 0 || $r['lon'] != 0))->values()->all();
            } catch (\Throwable) {
                return [];
            }
        });

        return response()->json(['results' => $results]);
    }

    public function reverse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $lat = round((float) $validated['lat'], 5);
        $lon = round((float) $validated['lon'], 5);
        $cacheKey = "osm_geo_rev:{$lat}:{$lon}";

        $data = Cache::remember($cacheKey, now()->addHours(24), function () use ($lat, $lon): ?array {
            try {
                $resp = Http::timeout(6)
                    ->withHeaders(['User-Agent' => 'MboaCar-SaaS/2.0 (contact@mboacar.com)'])
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'lat' => $lat,
                        'lon' => $lon,
                        'format' => 'jsonv2',
                        'addressdetails' => 1,
                        'accept-language' => 'fr',
                    ]);

                if (! $resp->successful()) {
                    return null;
                }

                $item = $resp->json();
                $addr = $item['address'] ?? [];
                $city = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? $addr['state'] ?? '';
                $suburb = $addr['suburb'] ?? $addr['neighbourhood'] ?? $addr['quarter'] ?? '';

                return [
                    'display_name' => (string) ($item['display_name'] ?? ''),
                    'short_label' => trim(($suburb && $suburb !== $city ? "{$suburb}, " : '').$city),
                    'city' => (string) $city,
                    'country' => (string) ($addr['country'] ?? ''),
                    'lat' => $lat,
                    'lon' => $lon,
                ];
            } catch (\Throwable) {
                return null;
            }
        });

        return response()->json(['result' => $data]);
    }

    private function resolveCountryCode(string $country): string
    {
        $clean = mb_strtolower(trim($country));
        if ($clean === '') {
            return '';
        }
        if (strlen($clean) === 2 && ctype_alpha($clean)) {
            return $clean;
        }

        return self::COUNTRY_CODES[$clean] ?? '';
    }
}
