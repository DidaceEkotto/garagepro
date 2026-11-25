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
