<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreVehicleReceptionRequest;
use App\Models\Agency;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\CourtesyVehicle;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleReception;
use App\Services\SecureUploadService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class VehicleReceptionController extends Controller
{
    public function index(SecureUploadService $uploadService): Response
    {
        $this->authorize('viewAny', VehicleReception::class);

        $receptions = VehicleReception::with(['customer', 'vehicle', 'agency', 'courtesyVehicle', 'receiver:id,name', 'appointment:id,reason,scheduled_at'])
            ->latest('received_at')
            ->paginate(20);

        $receptions->getCollection()->transform(function (VehicleReception $rec) use ($uploadService) {
            $rec->setAttribute('photo_urls', collect($rec->photos ?? [])
                ->map(fn ($path) => $uploadService->signedUrl($path, 60))
                ->values()
                ->all());
            $rec->setAttribute('received_at_formatted', $rec->received_at?->format('d/m/Y à H:i'));

            return $rec;
        });

        return Inertia::render('Tenant/Receptions/Index', [
            'receptions' => $receptions,
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone']),
            'vehicles' => Vehicle::orderBy('license_plate')->get(['id', 'customer_id', 'license_plate', 'brand', 'model', 'mileage']),
            'appointments' => Appointment::with('customer')->whereIn('status', [Appointment::STATUS_CONFIRMED, Appointment::STATUS_WALK_IN_QUEUE])->get(['id', 'customer_id', 'vehicle_id', 'reason']),
            'courtesyVehicles' => CourtesyVehicle::where('status', CourtesyVehicle::STATUS_AVAILABLE)->get(['id', 'name', 'license_plate', 'deposit_amount', 'current_mileage']),
            'allCourtesyVehicles' => CourtesyVehicle::with('agency:id,name')->orderBy('name')->get(),
            'activeCourtesyLoans' => VehicleReception::with(['customer:id,name,phone', 'courtesyVehicle:id,name,license_plate'])
                ->whereNotNull('courtesy_vehicle_id')
                ->whereNull('courtesy_returned_at')
                ->latest('received_at')
                ->get(['id', 'customer_id', 'courtesy_vehicle_id', 'courtesy_departure_mileage', 'courtesy_departure_fuel', 'courtesy_deposit_amount', 'received_at', 'estimated_exit_at']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', VehicleReception::class);

        return Inertia::render('Tenant/Receptions/Create', [
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'first_name', 'phone']),
            'vehicles' => Vehicle::orderBy('license_plate')->get(['id', 'customer_id', 'license_plate', 'brand', 'model', 'year', 'color', 'mileage']),
            'appointments' => Appointment::with(['customer:id,name', 'vehicle:id,license_plate'])
                ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_WALK_IN_QUEUE])
                ->latest('scheduled_at')
                ->get(['id', 'customer_id', 'vehicle_id', 'reason', 'scheduled_at']),
            'courtesyVehicles' => CourtesyVehicle::where('status', CourtesyVehicle::STATUS_AVAILABLE)
                ->orderBy('name')
                ->get(['id', 'name', 'license_plate', 'deposit_amount', 'current_mileage']),
        ]);
    }

    public function store(
        StoreVehicleReceptionRequest $request,
        TenantContext $tenantContext,
        SecureUploadService $uploadService
    ): RedirectResponse {
        $data = $request->validated();
        $tenantId = $tenantContext->id();

        $photoPaths = [];
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $photoPaths[] = $uploadService->storeImage($photo, $tenantId, 'receptions/photos');
            }
        }

        // Signature client manuscrite strictement FACULTATIVE (PNG vérifié par magic bytes)
        $signaturePath = null;
        if (! empty($data['client_signature_data']) && preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $data['client_signature_data'], $m)) {
            $binary = base64_decode($m[1], true);
            if ($binary !== false && strlen($binary) <= 250_000) {
                $imgInfo = @getimagesizefromstring($binary);
                if (is_array($imgInfo) && ($imgInfo[2] ?? null) === IMAGETYPE_PNG) {
                    $signaturePath = "tenants/{$tenantId}/receptions/signatures/".\Illuminate\Support\Str::random(32).'.png';
                    \Illuminate\Support\Facades\Storage::disk('local')->put($signaturePath, $binary);
                }
            }
        }

        $reception = DB::transaction(function () use ($data, $tenantId, $request, $photoPaths, $signaturePath) {
            $reception = VehicleReception::create([
                'tenant_id' => $tenantId,
                'agency_id' => $data['agency_id'],
                'customer_id' => $data['customer_id'],
                'vehicle_id' => $data['vehicle_id'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'received_by' => $request->user('web')->id,
                'courtesy_vehicle_id' => $data['courtesy_vehicle_id'] ?? null,
                'courtesy_deposit_amount' => $data['courtesy_deposit_amount'] ?? 0,
                'courtesy_departure_mileage' => $data['courtesy_departure_mileage'] ?? null,
                'courtesy_departure_fuel' => $data['courtesy_departure_fuel'] ?? null,
                'visit_reason' => $data['visit_reason'],
                'mileage_in' => $data['mileage_in'],
                'fuel_level' => $data['fuel_level'],
                'dashboard_warnings' => $data['dashboard_warnings'] ?? [],
                'belongings_left' => $data['belongings_left'] ?? null,
                'damage_markers' => $data['damage_markers'] ?? [],
                'client_signature_path' => $signaturePath,
                'photos' => $photoPaths,
                'received_at' => now(),
                'estimated_exit_at' => $data['estimated_exit_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            Vehicle::where('id', $data['vehicle_id'])
                ->where('mileage', '<', $data['mileage_in'])
                ->update(['mileage' => $data['mileage_in']]);

            if (! empty($data['appointment_id'])) {
                Appointment::where('id', $data['appointment_id'])
                    ->update(['status' => Appointment::STATUS_RECEIVED]);
            }

            if (! empty($data['courtesy_vehicle_id'])) {
                CourtesyVehicle::where('id', $data['courtesy_vehicle_id'])
                    ->update(['status' => CourtesyVehicle::STATUS_LOANED]);
            }

            return $reception;
        });

        AuditLog::record('reception.created', subject: $reception, actor: $request->user('web'), tenantId: $tenantId, request: $request);

        return redirect()->route('tenant.receptions.index')
            ->with('success', 'Réception du véhicule enregistrée avec succès.');
    }

    public function edit(VehicleReception $reception, SecureUploadService $uploadService): Response
    {
        $this->authorize('update', $reception);

        $reception->load(['customer:id,name,first_name,phone', 'vehicle:id,license_plate,brand,model,year,mileage', 'agency:id,name', 'courtesyVehicle:id,name,license_plate']);

        $photoUrls = collect($reception->photos ?? [])
            ->map(fn ($path) => $uploadService->signedUrl($path, 60))
            ->values()
            ->all();

        return Inertia::render('Tenant/Receptions/Edit', [
            'reception' => [
                'id' => $reception->id,
                'agency_id' => $reception->agency_id,
                'customer' => $reception->customer,
                'vehicle' => $reception->vehicle,
                'courtesy_vehicle' => $reception->courtesyVehicle,
                'courtesy_deposit_amount' => (int) ($reception->courtesy_deposit_amount ?? 0),
                'visit_reason' => $reception->visit_reason,
                'mileage_in' => (int) $reception->mileage_in,
                'fuel_level' => (int) $reception->fuel_level,
                'belongings_left' => $reception->belongings_left ?? '',
                'damage_markers' => is_array($reception->damage_markers) ? array_values($reception->damage_markers) : [],
                'notes' => $reception->notes ?? '',
                'received_at_formatted' => $reception->received_at?->format('d/m/Y à H:i'),
                'photo_urls' => $photoUrls,
            ],
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(
        Request $request,
        VehicleReception $reception,
        TenantContext $tenantContext,
        SecureUploadService $uploadService
    ): RedirectResponse {
        $this->authorize('update', $reception);

        $data = $request->validate([
            'agency_id' => ['required', 'integer', 'exists:agencies,id'],
            'visit_reason' => ['required', 'in:entretien,panne,controle,accident'],
            'mileage_in' => ['required', 'integer', 'min:0'],
            'fuel_level' => ['required', 'integer', 'min:0', 'max:100'],
            'belongings_left' => ['nullable', 'string', 'max:1000'],
            'damage_markers' => ['nullable', 'array', 'max:60'],
            'courtesy_deposit_amount' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['image', 'max:8192'],
        ]);

        $tenantId = $tenantContext->id();
        $photoPaths = is_array($reception->photos) ? $reception->photos : [];
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $photoPaths[] = $uploadService->storeImage($photo, $tenantId, 'receptions/photos');
            }
        }

        $reception->update([
            'agency_id' => $data['agency_id'],
            'visit_reason' => $data['visit_reason'],
            'mileage_in' => $data['mileage_in'],
            'fuel_level' => $data['fuel_level'],
            'belongings_left' => $data['belongings_left'] ?? null,
            'damage_markers' => array_key_exists('damage_markers', $data) ? ($data['damage_markers'] ?? []) : $reception->damage_markers,
            'courtesy_deposit_amount' => $data['courtesy_deposit_amount'] ?? $reception->courtesy_deposit_amount,
            'notes' => $data['notes'] ?? null,
            'photos' => $photoPaths,
        ]);

        Vehicle::where('id', $reception->vehicle_id)
            ->where('mileage', '<', $data['mileage_in'])
            ->update(['mileage' => $data['mileage_in']]);

        AuditLog::record('reception.updated', subject: $reception, actor: $request->user('web'), tenantId: $tenantId, request: $request);

        return redirect()->route('tenant.receptions.index')->with('success', 'Fiche de réception mise à jour avec succès.');
    }

    public function returnCourtesy(Request $request, VehicleReception $reception): RedirectResponse
    {
        $this->authorize('update', $reception);

        $validated = $request->validate([
            'courtesy_return_mileage' => ['nullable', 'integer', 'min:0'],
            'courtesy_return_fuel' => ['nullable', 'integer', 'min:0', 'max:100'],
            'courtesy_return_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($reception->courtesy_vehicle_id && $reception->courtesy_returned_at === null) {
            DB::transaction(function () use ($reception, $validated): void {
                $reception->update([
                    'courtesy_returned_at' => now(),
                    'courtesy_return_mileage' => $validated['courtesy_return_mileage'] ?? null,
                    'courtesy_return_fuel' => $validated['courtesy_return_fuel'] ?? null,
                    'courtesy_return_notes' => $validated['courtesy_return_notes'] ?? null,
                ]);
                $update = ['status' => CourtesyVehicle::STATUS_AVAILABLE];
                if (! empty($validated['courtesy_return_mileage'])) {
                    $update['current_mileage'] = (int) $validated['courtesy_return_mileage'];
                }
                CourtesyVehicle::where('id', $reception->courtesy_vehicle_id)->update($update);
            });
        }

        return back()->with('success', 'Retour et état des lieux du véhicule de courtoisie enregistrés.');
    }

    public function storeCourtesyVehicle(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $this->authorize('create', VehicleReception::class);

        $validated = $request->validate([
            'agency_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('agencies', 'id')->where('tenant_id', $tenantContext->id())],
            'name' => ['required', 'string', 'max:120'],
            'license_plate' => ['required', 'string', 'max:30'],
            'current_mileage' => ['nullable', 'integer', 'min:0'],
            'deposit_amount' => ['nullable', 'integer', 'min:0'],
        ]);

        CourtesyVehicle::create([
            'tenant_id' => $tenantContext->id(),
            'agency_id' => $validated['agency_id'],
            'name' => $validated['name'],
            'license_plate' => strtoupper(trim($validated['license_plate'])),
            'status' => CourtesyVehicle::STATUS_AVAILABLE,
            'current_mileage' => (int) ($validated['current_mileage'] ?? 0),
            'deposit_amount' => (int) ($validated['deposit_amount'] ?? 0),
        ]);

        return back()->with('success', 'Véhicule de courtoisie ajouté à la flotte.');
    }

    public function updateCourtesyStatus(Request $request, CourtesyVehicle $courtesyVehicle): RedirectResponse
    {
        $this->authorize('create', VehicleReception::class);

        $validated = $request->validate([
            'status' => ['required', \Illuminate\Validation\Rule::in([
                CourtesyVehicle::STATUS_AVAILABLE,
                CourtesyVehicle::STATUS_LOANED,
                CourtesyVehicle::STATUS_MAINTENANCE,
            ])],
        ]);

        $courtesyVehicle->update(['status' => $validated['status']]);

        return back()->with('success', 'Statut du véhicule de courtoisie mis à jour.');
    }
}
