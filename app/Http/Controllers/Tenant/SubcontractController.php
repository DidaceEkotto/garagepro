<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\RepairOrder;
use App\Models\SubcontractIntervention;
use App\Models\Vehicle;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubcontractController extends Controller
{
    public function index(): Response
    {
        $interventions = SubcontractIntervention::with([
            'repairOrder:id,order_number',
            'vehicle:id,license_plate,brand,model',
            'customer:id,name,phone',
            'agency:id,name',
        ])->latest('id')->get();

        return Inertia::render('Tenant/Subcontracting/Index', [
            'interventions' => $interventions,
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'repairOrders' => RepairOrder::with('vehicle:id,license_plate')->latest('id')->take(50)->get(['id', 'order_number', 'vehicle_id', 'customer_id']),
            'vehicles' => Vehicle::orderBy('license_plate')->get(['id', 'customer_id', 'license_plate', 'brand', 'model']),
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone']),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'agency_id' => ['nullable', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'repair_order_id' => ['nullable', 'integer', Rule::exists('repair_orders', 'id')->where('tenant_id', $tenantId)],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'type' => ['required', Rule::in(['sous_traitance', 'depannage', 'remorquage'])],
            'provider_name' => ['required', 'string', 'max:150'],
            'provider_phone' => ['nullable', 'string', 'max:50'],
            'description' => ['required', 'string', 'max:2000'],
            'location_address' => ['nullable', 'string', 'max:255'],
            'cost_amount' => ['required', 'integer', 'min:0'],
            'billed_amount' => ['required', 'integer', 'min:0'],
            'performed_at' => ['nullable', 'date'],
        ]);

        SubcontractIntervention::create([
            'tenant_id' => $tenantId,
            'agency_id' => $validated['agency_id'] ?? Agency::where('is_active', true)->value('id'),
            'repair_order_id' => $validated['repair_order_id'] ?? null,
            'vehicle_id' => $validated['vehicle_id'] ?? null,
            'customer_id' => $validated['customer_id'] ?? null,
            'type' => $validated['type'],
            'provider_name' => $validated['provider_name'],
            'provider_phone' => $validated['provider_phone'] ?? null,
            'description' => $validated['description'],
            'location_address' => $validated['location_address'] ?? null,
            'cost_amount' => $validated['cost_amount'],
            'billed_amount' => $validated['billed_amount'],
            'status' => 'planifie',
            'performed_at' => $validated['performed_at'] ?? now(),
        ]);

        return back()->with('success', 'Intervention enregistrée avec succès.');
    }

    public function updateStatus(Request $request, SubcontractIntervention $intervention): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['planifie', 'en_cours', 'termine', 'facture'])],
        ]);

        $intervention->update(['status' => $validated['status']]);

        return back()->with('success', 'Statut mis à jour.');
    }

    public function destroy(SubcontractIntervention $intervention): RedirectResponse
    {
        $intervention->delete();

        return back()->with('success', 'Intervention supprimée.');
    }
}
