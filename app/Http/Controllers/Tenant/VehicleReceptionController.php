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
    public function index(): Response
    {
        $this->authorize('viewAny', VehicleReception::class);

        $receptions = VehicleReception::with(['customer', 'vehicle', 'agency', 'courtesyVehicle'])
            ->latest('received_at')
            ->paginate(20);

        return Inertia::render('Tenant/Receptions/Index', [
            'receptions' => $receptions,
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone']),
            'vehicles' => Vehicle::orderBy('license_plate')->get(['id', 'customer_id', 'license_plate', 'brand', 'model', 'mileage']),
            'appointments' => Appointment::with('customer')->whereIn('status', [Appointment::STATUS_CONFIRMED, Appointment::STATUS_WALK_IN_QUEUE])->get(['id', 'customer_id', 'vehicle_id', 'reason']),
            'courtesyVehicles' => CourtesyVehicle::where('status', CourtesyVehicle::STATUS_AVAILABLE)->get(['id', 'name', 'license_plate', 'deposit_amount']),
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

        $reception = DB::transaction(function () use ($data, $tenantId, $request, $photoPaths) {
            $reception = VehicleReception::create([
                'tenant_id' => $tenantId,
                'agency_id' => $data['agency_id'],
                'customer_id' => $data['customer_id'],
                'vehicle_id' => $data['vehicle_id'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'received_by' => $request->user('web')->id,
                'courtesy_vehicle_id' => $data['courtesy_vehicle_id'] ?? null,
                'courtesy_deposit_amount' => $data['courtesy_deposit_amount'] ?? 0,
                'visit_reason' => $data['visit_reason'],
                'mileage_in' => $data['mileage_in'],
                'fuel_level' => $data['fuel_level'],
                'dashboard_warnings' => $data['dashboard_warnings'] ?? [],
                'belongings_left' => $data['belongings_left'] ?? null,
                'damage_markers' => $data['damage_markers'] ?? [],
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

        return back()->with('success', 'Réception du véhicule enregistrée avec succès.');
    }

    public function returnCourtesy(Request $request, VehicleReception $reception): RedirectResponse
    {
        $this->authorize('update', $reception);

        if ($reception->courtesy_vehicle_id && $reception->courtesy_returned_at === null) {
            DB::transaction(function () use ($reception): void {
                $reception->update(['courtesy_returned_at' => now()]);
                CourtesyVehicle::where('id', $reception->courtesy_vehicle_id)
                    ->update(['status' => CourtesyVehicle::STATUS_AVAILABLE]);
            });
        }

        return back()->with('success', 'Retour du véhicule de courtoisie enregistré.');
    }
}
