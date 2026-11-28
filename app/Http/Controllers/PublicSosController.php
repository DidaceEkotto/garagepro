<?php

namespace App\Http\Controllers;

use App\Models\ClientReview;
use App\Models\Country;
use App\Models\RoadsideSosOffer;
use App\Models\RoadsideSosRequest;
use App\Models\Tenant;
use App\Models\TenantService;
use App\Services\GreenApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page publique SOS Dépannage & Annuaire Géolocalisé façon Airbnb (OpenStreetMap).
 */
class PublicSosController extends Controller
{
    public function index(): Response
    {
        $countries = Country::active()->orderBy('name')->get(['code', 'name', 'dial_code']);
        if ($countries->isEmpty()) {
            $countries = collect(Country::defaultAfricanCountries());
        }

        return Inertia::render('Public/SosRoadside', [
            'countries' => $countries,
        ]);
    }

    /**
     * Retourne les établissements géolocalisés à proximité par lots de 20 (infinite load + skeleton)
     * ainsi que tous les marqueurs de la zone pour la carte interactive sticky.
     */
    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'integer', 'min:1', 'max:250'],
            'type' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:40'],
        ]);

        $lat = (float) $validated['lat'];
        $lon = (float) $validated['lon'];
        $radius = (int) ($validated['radius_km'] ?? 50);
        $type = trim((string) ($validated['type'] ?? ''));
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = (int) ($validated['per_page'] ?? 20);

        $tenants = Tenant::query()
            ->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIALING])
            ->where('b2b_directory_visible', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($type !== '', fn ($q) => $q->where('type', $type))
            ->get([
                'id',
                'name',
                'slug',
                'type',
                'city',
                'country',
                'phone',
                'formatted_location',
                'latitude',
                'longitude',
                'sos_enabled',
                'showcase_photos',
            ]);

        $tenantIds = $tenants->pluck('id')->all();

        $serviceStats = TenantService::withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->where('is_active', true)
            ->selectRaw('tenant_id, COUNT(*) as services_count, MIN(CASE WHEN price > 0 THEN price ELSE NULL END) as min_price')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $reviewStats = ClientReview::withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->where('published_publicly', true)
            ->whereNotNull('rating')
            ->selectRaw('tenant_id, COUNT(*) as reviews_count, AVG(rating) as avg_rating')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $allNearby = $tenants
            ->map(function (Tenant $t) use ($lat, $lon, $serviceStats, $reviewStats) {
                $dist = RoadsideSosRequest::haversineKm($lat, $lon, (float) $t->latitude, (float) $t->longitude);
                $svc = $serviceStats->get($t->id);
                $rev = $reviewStats->get($t->id);

                return [
                    'id' => $t->id,
                    'slug' => $t->slug,
                    'public_url' => route('public.garage.show', ['slug' => $t->slug]),
                    'name' => $t->name,
                    'type' => $t->type,
                    'type_label' => match ($t->type) {
                        'centre_technique' => 'Centre Technique',
                        'assurance' => 'Assurance Auto',
                        'boutique_pieces', 'pieces' => 'Boutique Pièces',
                        default => 'Garage / Dépannage',
                    },
                    'city' => $t->city,
                    'formatted_location' => $t->formatted_location ?: $t->city,
                    'phone' => $t->phone,
                    'latitude' => (float) $t->latitude,
                    'longitude' => (float) $t->longitude,
                    'distance_km' => $dist,
                    'sos_enabled' => (bool) $t->sos_enabled,
                    'photos' => $t->showcasePhotoUrls(),
                    'services_count' => (int) ($svc->services_count ?? 0),
                    'min_price' => $svc && $svc->min_price ? (int) $svc->min_price : null,
                    'rating' => $rev && $rev->avg_rating ? round((float) $rev->avg_rating, 2) : null,
                    'reviews_count' => (int) ($rev->reviews_count ?? 0),
                ];
            })
            ->filter(fn (array $row) => $row['distance_km'] <= $radius)
            ->sortBy('distance_km')
            ->values();

        $total = $allNearby->count();
        $offset = ($page - 1) * $perPage;
        $batch = $allNearby->slice($offset, $perPage)->values()->all();
        $hasMore = ($offset + count($batch)) < $total;

        return response()->json([
            'ok' => true,
            'count' => count($batch),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
            'establishments' => $batch,
            // Page 1 retourne tous les marqueurs de la zone pour les afficher tous sur la carte
            'map_pins' => $page === 1 ? $allNearby->all() : null,
        ]);
    }

    public function store(Request $request, GreenApiService $greenApi): RedirectResponse
    {
        $validated = $request->validate([
            'driver_name' => ['required', 'string', 'min:2', 'max:120'],
            'driver_first_name' => ['nullable', 'string', 'max:120'],
            'driver_dial_code' => ['nullable', 'string', 'max:16'],
            'driver_phone' => ['required', 'string', 'min:6', 'max:40'],
            'vehicle_brand' => ['nullable', 'string', 'max:80'],
            'vehicle_model' => ['nullable', 'string', 'max:80'],
            'vehicle_plate' => ['nullable', 'string', 'max:40'],
            'vehicle_info' => ['nullable', 'string', 'max:160'],
            'breakdown_type' => ['required', 'in:batterie,crevaison,surchauffe,moteur,accident,cle,remorquage'],
            'description' => ['nullable', 'string', 'max:1500'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_label' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $storedPhotos = [];
        if ($request->hasFile('photo')) {
            $ext = strtolower($request->file('photo')->getClientOriginalExtension() ?: 'jpg');
            $storedPhotos[] = $request->file('photo')->storeAs('sos-photos', Str::random(32).'.'.$ext, 'public');
        }
        foreach ($request->file('photos', []) as $file) {
            $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $storedPhotos[] = $file->storeAs('sos-photos', Str::random(32).'.'.$ext, 'public');
        }

        $dialCode = trim((string) ($validated['driver_dial_code'] ?? ''));
        $rawPhone = preg_replace('/\s+/', '', (string) $validated['driver_phone']);
        $fullPhone = ($dialCode !== '' && ! str_starts_with($rawPhone, '+')) ? $dialCode.$rawPhone : $rawPhone;

        $fullDriverName = trim($validated['driver_name'].(! empty($validated['driver_first_name']) ? ' '.$validated['driver_first_name'] : ''));

        $brand = trim((string) ($validated['vehicle_brand'] ?? ''));
        $model = trim((string) ($validated['vehicle_model'] ?? ''));
        $plate = strtoupper(trim((string) ($validated['vehicle_plate'] ?? '')));
        $composedVehicleInfo = trim(implode(' ', array_filter([$brand, $model, $plate ? "({$plate})" : ''])));
        $vehicleInfo = $composedVehicleInfo !== '' ? $composedVehicleInfo : ($validated['vehicle_info'] ?? null);

        $sos = RoadsideSosRequest::create([
            'token' => Str::random(40),
            'driver_name' => $fullDriverName,
            'driver_first_name' => $validated['driver_first_name'] ?? null,
            'driver_dial_code' => $dialCode ?: null,
            'driver_phone' => $fullPhone,
            'vehicle_info' => $vehicleInfo,
            'vehicle_brand' => $brand ?: null,
            'vehicle_model' => $model ?: null,
            'vehicle_plate' => $plate ?: null,
            'breakdown_type' => $validated['breakdown_type'],
            'description' => $validated['description'] ?? null,
            'latitude' => (float) $validated['latitude'],
            'longitude' => (float) $validated['longitude'],
            'location_label' => $validated['location_label'] ?? null,
            'photo_path' => $storedPhotos[0] ?? null,
            'photos' => ! empty($storedPhotos) ? $storedPhotos : null,
            'status' => RoadsideSosRequest::STATUS_OPEN,
        ]);

        // Diffusion WhatsApp aux 5 garages SOS les plus proches
        $candidates = Tenant::whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIALING])
            ->where('type', Tenant::TYPE_GARAGE)
            ->where('sos_enabled', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNotNull('phone')
            ->get(['id', 'name', 'phone', 'latitude', 'longitude', 'sos_radius_km']);

        $notified = 0;
        foreach ($candidates as $garage) {
            $dist = RoadsideSosRequest::haversineKm($sos->latitude, $sos->longitude, (float) $garage->latitude, (float) $garage->longitude);
            if ($dist <= max(5, (int) ($garage->sos_radius_km ?: 35)) && $notified < 5) {
                try {
                    $mapUrl = "https://www.openstreetmap.org/?mlat={$sos->latitude}&mlon={$sos->longitude}#map=16/{$sos->latitude}/{$sos->longitude}";
                    $msg = "🚨 *ALERTE SOS DÉPANNAGE ({$dist} km)*\n"
                        ."Panne : *{$sos->breakdown_type}* | Véhicule : ".($sos->vehicle_info ?: 'Non précisé')."\n"
                        ."Conducteur : {$sos->driver_name} ({$sos->driver_phone})\n"
                        ."Position GPS : {$mapUrl}\n\n"
                        ."Connectez-vous sur MboaCar → SOS Dépannage pour envoyer votre tarif et délai d'arrivée !";
                    $greenApi->sendMessage($garage->phone, $msg);
                    $notified++;
                } catch (\Throwable $e) {
                    Log::warning('SOS WhatsApp dispatch failed', ['tenant_id' => $garage->id, 'error' => $e->getMessage()]);
                }
            }
        }

        return redirect()->route('public.sos.track', ['token' => $sos->token])
            ->with('success', 'Votre alerte SOS a été transmise aux garages partenaires à proximité !');
    }

    public function track(string $token): Response
    {
        $sos = RoadsideSosRequest::with([
            'acceptedTenant:id,name,phone,city,formatted_location,latitude,longitude',
            'offers.tenant:id,name,phone,city,formatted_location,latitude,longitude',
        ])->where('token', $token)->firstOrFail();

        return Inertia::render('Public/SosTrack', [
            'sos' => [
                'token' => $sos->token,
                'driver_name' => $sos->driver_name,
                'vehicle_info' => $sos->vehicle_info,
                'breakdown_type' => $sos->breakdown_type,
                'description' => $sos->description,
                'latitude' => $sos->latitude,
                'longitude' => $sos->longitude,
                'location_label' => $sos->location_label,
                'status' => $sos->status,
                'accepted_price' => $sos->accepted_price,
                'accepted_eta_minutes' => $sos->accepted_eta_minutes,
                'accepted_tenant' => $sos->acceptedTenant,
                'created_at' => $sos->created_at?->format('d/m/Y à H:i'),
                'offers' => $sos->offers->map(fn (RoadsideSosOffer $o) => [
                    'id' => $o->id,
                    'price' => $o->price,
                    'eta_minutes' => $o->eta_minutes,
                    'distance_km' => $o->distance_km,
                    'message' => $o->message,
                    'status' => $o->status,
                    'garage_name' => $o->tenant?->name,
                    'garage_phone' => $o->tenant?->phone,
                    'garage_city' => $o->tenant?->city,
                    'garage_lat' => $o->tenant?->latitude ? (float) $o->tenant->latitude : null,
                    'garage_lon' => $o->tenant?->longitude ? (float) $o->tenant->longitude : null,
                ])->values(),
            ],
        ]);
    }

    public function acceptOffer(string $token, RoadsideSosOffer $offer, GreenApiService $greenApi): RedirectResponse
    {
        $sos = RoadsideSosRequest::where('token', $token)->firstOrFail();

        if ((int) $offer->sos_request_id !== (int) $sos->id || $sos->status !== RoadsideSosRequest::STATUS_OPEN) {
            return back()->with('error', 'Cette offre n\'est plus disponible.');
        }

        DB::transaction(function () use ($sos, $offer): void {
            $offer->update(['status' => RoadsideSosOffer::STATUS_ACCEPTED]);
            RoadsideSosOffer::where('sos_request_id', $sos->id)
                ->where('id', '!=', $offer->id)
                ->update(['status' => RoadsideSosOffer::STATUS_REJECTED]);

            $sos->update([
                'status' => RoadsideSosRequest::STATUS_ACCEPTED,
                'accepted_tenant_id' => $offer->tenant_id,
                'accepted_price' => $offer->price,
                'accepted_eta_minutes' => $offer->eta_minutes,
                'accepted_at' => now(),
            ]);
        });

        $garage = Tenant::find($offer->tenant_id);
        if ($garage && ! empty($garage->phone)) {
            try {
                $greenApi->sendMessage(
                    $garage->phone,
                    "✅ *OFFRE SOS ACCEPTÉE !*\nLe conducteur {$sos->driver_name} ({$sos->driver_phone}) a validé votre offre de ".number_format($offer->price, 0, ',', ' ')." FCFA (ETA {$offer->eta_minutes} min)."
                );
            } catch (\Throwable) {
                // non-blocking
            }
        }

        return back()->with('success', 'Offre acceptée ! Le dépanneur a été prévenu et se met en route.');
    }
}
