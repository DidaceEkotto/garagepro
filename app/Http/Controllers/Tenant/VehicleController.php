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
