<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\RoadsideSosOffer;
use App\Models\RoadsideSosRequest;
use App\Services\GreenApiService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord SOS Dépannage côté Garage : réception des alertes GPS à proximité,
 * envoi d'offres (tarif + délai d'arrivée) et suivi des interventions acceptées.
 */
class RoadsideSosController extends Controller
{
    public function index(TenantContext $tenantContext): Response
    {
        return Inertia::render('Tenant/Sos/Index', $this->buildDashboardPayload($tenantContext));
    }

    /**
     * Flux JSON léger pour l'actualisation automatique en direct de /depannage-sos
     * (détecte immédiatement quand un conducteur valide une offre ou envoie un nouveau SOS).
     */
    public function feed(TenantContext $tenantContext): JsonResponse
    {
        return response()->json([
            'ok' => true,
            ...$this->buildDashboardPayload($tenantContext),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboardPayload(TenantContext $tenantContext): array
    {
        $tenant = $tenantContext->getTenant();
        $lat = $tenant->latitude;
        $lon = $tenant->longitude;
        $radius = max(10, (int) ($tenant->sos_radius_km ?: 35));

        $openRequests = RoadsideSosRequest::with(['offers' => fn ($q) => $q->where('tenant_id', $tenant->id)])
            ->where('status', RoadsideSosRequest::STATUS_OPEN)
            ->where('created_at', '>=', now()->subHours(24))
            ->latest('id')
            ->get()
            ->map(function (RoadsideSosRequest $r) use ($lat, $lon) {
                $dist = ($lat !== null && $lon !== null)
                    ? RoadsideSosRequest::haversineKm((float) $lat, (float) $lon, $r->latitude, $r->longitude)
                    : null;

                return [
                    'id' => $r->id,
                    'driver_name' => $r->driver_name,
                    'driver_phone' => $r->driver_phone,
                    'vehicle_info' => $r->vehicle_info,
                    'breakdown_type' => $r->breakdown_type,
                    'description' => $r->description,
                    'latitude' => $r->latitude,
                    'longitude' => $r->longitude,
                    'location_label' => $r->location_label,
                    'distance_km' => $dist,
                    'created_at' => $r->created_at?->format('d/m H:i'),
                    'my_offer' => $r->offers->first(),
                ];
            })
            ->filter(fn (array $row) => $row['distance_km'] === null || $row['distance_km'] <= $radius)
            ->sortBy(fn (array $row) => $row['distance_km'] ?? 9999)
            ->values();

        $myMissions = RoadsideSosRequest::where('accepted_tenant_id', $tenant->id)
            ->latest('accepted_at')
            ->take(20)
            ->get();

        return [
            'openRequests' => $openRequests,
            'myMissions' => $myMissions,
            'tenantLocation' => [
                'latitude' => $lat,
                'longitude' => $lon,
                'formatted_location' => $tenant->formatted_location ?: $tenant->city,
                'sos_enabled' => (bool) $tenant->sos_enabled,
                'sos_radius_km' => $radius,
            ],
        ];
    }

    public function sendOffer(
        Request $request,
        RoadsideSosRequest $sosRequest,
        TenantContext $tenantContext,
        GreenApiService $greenApi
    ): RedirectResponse {
        if ($sosRequest->status !== RoadsideSosRequest::STATUS_OPEN) {
            return back()->with('error', 'Cette demande SOS a déjà été attribuée ou clôturée.');
        }

        $validated = $request->validate([
            'price' => ['required', 'integer', 'min:0', 'max:10000000'],
            'eta_minutes' => ['required', 'integer', 'min:5', 'max:360'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $tenant = $tenantContext->getTenant();
        $dist = ($tenant->latitude !== null && $tenant->longitude !== null)
            ? RoadsideSosRequest::haversineKm((float) $tenant->latitude, (float) $tenant->longitude, $sosRequest->latitude, $sosRequest->longitude)
            : null;

        RoadsideSosOffer::updateOrCreate(
            ['sos_request_id' => $sosRequest->id, 'tenant_id' => $tenant->id],
            [
                'user_id' => $request->user('web')->id,
                'price' => (int) $validated['price'],
                'eta_minutes' => (int) $validated['eta_minutes'],
                'distance_km' => $dist,
                'message' => $validated['message'] ?? null,
                'status' => RoadsideSosOffer::STATUS_PENDING,
            ]
        );

        try {
            $trackUrl = url("/sos-depannage/{$sosRequest->token}");
            $greenApi->sendMessage(
                $sosRequest->driver_phone,
                "🛠️ *{$tenant->name}* propose d'intervenir pour votre panne !\nTarif estimé : *".number_format((int) $validated['price'], 0, ',', ' ')." FCFA* • Arrivée estimée : *{$validated['eta_minutes']} min*.\nValidez l'offre ici : {$trackUrl}"
            );
        } catch (\Throwable) {
            // non-blocking
        }

        return back()->with('success', 'Offre de dépannage envoyée au conducteur.');
    }

    public function updateMissionStatus(Request $request, RoadsideSosRequest $sosRequest, TenantContext $tenantContext): RedirectResponse
    {
        if ((int) $sosRequest->accepted_tenant_id !== (int) $tenantContext->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:on_the_way,resolved'],
        ]);

        $sosRequest->update([
            'status' => $validated['status'],
            'resolved_at' => $validated['status'] === 'resolved' ? now() : $sosRequest->resolved_at,
        ]);

        return back()->with('success', 'Statut de la mission SOS mis à jour.');
    }
}
