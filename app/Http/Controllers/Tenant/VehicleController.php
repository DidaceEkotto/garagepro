<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreVehicleRequest;
use App\Models\AuditLog;
use App\Models\Vehicle;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class VehicleController extends Controller
{
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

        $vehicle->load(['customer', 'receptions', 'appointments']);

        return Inertia::render('Tenant/Vehicles/Show', [
            'vehicle' => $vehicle,
        ]);
    }
}
