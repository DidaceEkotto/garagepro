<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreVehicleRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VehicleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vehicle::class);

        $search = trim((string) $request->query('q', ''));

        $vehicles = Vehicle::query()
            ->with('customer:id,name,first_name,phone')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('license_plate', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%")
                        ->orWhere('model', 'like', "%{$search}%")
                        ->orWhere('vin', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($cq) use ($search): void {
                            $cq->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $vehicles->getCollection()->each->ensurePublicToken();

        return Inertia::render('Tenant/Vehicles/Index', [
            'vehicles' => $vehicles,
            'search' => $search,
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'first_name', 'phone']),
        ]);
    }
    public function store(StoreVehicleRequest $request, TenantContext $tenantContext): RedirectResponse
    {
        $vehicle = Vehicle::create([
            ...$request->validated(),
            'tenant_id' => $tenantContext->id(),
            'license_plate' => strtoupper($request->string('license_plate')->toString()),
        ]);

        AuditLog::record('vehicle.created', subject: $vehicle, actor: $request->user('web'), tenantId: $tenantContext->id(), request: $request);

        return back()->with('success', "Véhicule {$vehicle->license_plate} ajouté.");
    }

    public function qrLabel(Vehicle $vehicle, TenantContext $tenantContext): \Illuminate\Contracts\View\View
    {
        $this->authorize('view', $vehicle);
        $token = $vehicle->ensurePublicToken();

        return view('pdf.vehicle-qr-label', [
            'vehicle' => $vehicle,
            'tenant' => $tenantContext->getTenant(),
            'scanUrl' => url("/vehicule-scan/{$token}"),
        ]);
    }

    /**
     * OCR Carte Grise / Plaque d'immatriculation / VIN via Gemini Vision
     * pour pré-remplir automatiquement la fiche véhicule sans saisie manuelle.
     */
    public function ocrScan(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', Vehicle::class);

        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $apiKey = (string) config('services.gemini.api_key');
        if ($apiKey === '') {
            return response()->json(['ok' => false, 'error' => 'Service OCR IA non configuré (clé Gemini manquante).'], 422);
        }

        $file = $request->file('photo');
        $bytes = file_get_contents($file->getRealPath());
        $mime = $file->getMimeType() ?: 'image/jpeg';

        $model = (string) config('services.gemini.model', 'gemini-flash-lite-latest');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $prompt = 'Tu es un lecteur OCR automobile. Extrais uniquement les champs visibles sur cette carte grise, plaque d\'immatriculation ou étiquette VIN. '
            .'Réponds STRICTEMENT en JSON valide (sans markdown) avec les clés : '
            .'{"license_plate": string|null, "brand": string|null, "model": string|null, "year": int|null, "vin": string|null, "fuel_type": "essence"|"diesel"|"hybride"|"electrique"|null, "color": string|null}.';

        try {
            $resp = \Illuminate\Support\Facades\Http::timeout(25)->post($url, [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                        ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                    ],
                ]],
                'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 300],
            ]);

            if (! $resp->successful()) {
                return response()->json(['ok' => false, 'error' => 'Échec de la lecture OCR IA.'], 502);
            }

            $rawText = (string) ($resp->json('candidates.0.content.parts.0.text') ?? '');
            $cleanJson = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $rawText));
            $parsed = json_decode($cleanJson, true);

            if (! is_array($parsed)) {
                return response()->json(['ok' => false, 'error' => 'Impossible d\'extraire les informations de cette photo.'], 422);
            }

            return response()->json([
                'ok' => true,
                'data' => [
                    'license_plate' => isset($parsed['license_plate']) ? strtoupper(trim((string) $parsed['license_plate'])) : null,
                    'brand' => $parsed['brand'] ?? null,
                    'model' => $parsed['model'] ?? null,
                    'year' => is_numeric($parsed['year'] ?? null) ? (int) $parsed['year'] : null,
                    'vin' => isset($parsed['vin']) ? strtoupper(trim((string) $parsed['vin'])) : null,
                    'fuel_type' => in_array($parsed['fuel_type'] ?? null, ['essence', 'diesel', 'hybride', 'electrique'], true) ? $parsed['fuel_type'] : null,
                    'color' => $parsed['color'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Erreur réseau lors du scan OCR.'], 500);
        }
    }

    public function update(Request $request, Vehicle $vehicle, TenantContext $tenantContext): RedirectResponse
    {
        $this->authorize('update', $vehicle);
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'customer_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'license_plate' => ['required', 'string', 'max:30', \Illuminate\Validation\Rule::unique('vehicles', 'license_plate')->where('tenant_id', $tenantId)->ignore($vehicle->id)],
            'brand' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:80'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'vin' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:50'],
            'fuel_type' => ['nullable', 'string', 'max:40'],
            'transmission' => ['nullable', 'string', 'max:40'],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'first_registration_date' => ['nullable', 'date'],
            'next_service_date' => ['nullable', 'date'],
            'next_service_mileage' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $vehicle->update([
            ...$validated,
            'license_plate' => strtoupper(trim($validated['license_plate'])),
        ]);

        AuditLog::record('vehicle.updated', subject: $vehicle, actor: $request->user('web'), tenantId: $tenantId, request: $request);

        return back()->with('success', "Véhicule {$vehicle->license_plate} mis à jour.");
    }

    public function show(Vehicle $vehicle): Response
    {
        $this->authorize('view', $vehicle);

        $vehicle->ensurePublicToken();
        $vehicle->load(['customer', 'receptions', 'appointments']);

        return Inertia::render('Tenant/Vehicles/Show', [
            'vehicle' => $vehicle,
        ]);
    }
}
