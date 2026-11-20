<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\RepairOrder;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Services\GreenApiService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InsuranceController extends Controller
{
    public function index(TenantContext $tenantContext): Response
    {
        $tenantId = $tenantContext->id();

        $policies = InsurancePolicy::with(['customer:id,name,phone', 'vehicle:id,license_plate,brand,model'])
            ->latest('id')
            ->get()
            ->map(fn (InsurancePolicy $p) => [
                'id' => $p->id,
                'policy_number' => $p->policy_number,
                'insurer_name' => $p->insurer_name,
                'coverage_type' => $p->coverage_type,
                'premium_amount' => $p->premium_amount,
                'deductible_amount' => $p->deductible_amount,
                'starts_at' => $p->starts_at?->format('d/m/Y'),
                'expires_at' => $p->expires_at?->format('d/m/Y'),
                'status' => $p->status,
                'customer' => $p->customer,
                'vehicle' => $p->vehicle,
            ]);

        $claims = InsuranceClaim::withoutTenantScope()
            ->with([
                'customer:id,name,phone,whatsapp_phone',
                'vehicle:id,license_plate,brand,model',
                'policy:id,policy_number,insurer_name',
                'repairOrder:id,order_number',
                'insuranceTenant:id,name',
                'garageTenant:id,name',
            ])
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                    ->orWhere('insurance_tenant_id', $tenantId)
                    ->orWhere('garage_tenant_id', $tenantId);
            })
            ->latest('id')
            ->get()
            ->map(fn (InsuranceClaim $c) => [
                'id' => $c->id,
                'claim_number' => $c->claim_number,
                'incident_date' => $c->incident_date?->format('d/m/Y'),
                'description' => $c->description,
                'expert_name' => $c->expert_name,
                'estimated_amount' => $c->estimated_amount,
                'approved_amount' => $c->approved_amount,
                'insurance_share_percent' => $c->insurance_share_percent,
                'client_deductible_amount' => $c->client_deductible_amount,
                'status' => $c->status,
                'expert_notes' => $c->expert_notes,
                'customer' => $c->customer,
                'vehicle' => $c->vehicle,
                'policy' => $c->policy,
                'repair_order' => $c->repairOrder,
                'insurance_tenant' => $c->insuranceTenant,
                'garage_tenant' => $c->garageTenant,
            ]);

        return Inertia::render('Tenant/Insurance/Index', [
            'policies' => $policies,
            'claims' => $claims,
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone']),
            'vehicles' => Vehicle::orderBy('license_plate')->get(['id', 'customer_id', 'license_plate', 'brand', 'model']),
            'repairOrders' => RepairOrder::with('vehicle:id,license_plate')->latest('id')->take(40)->get(['id', 'order_number', 'vehicle_id', 'customer_id']),
            'insuranceTenants' => Tenant::where('type', Tenant::TYPE_INSURANCE)->orderBy('name')->get(['id', 'name']),
            'garageTenants' => Tenant::where('type', Tenant::TYPE_GARAGE)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storePolicy(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId)],
            'policy_number' => ['required', 'string', 'max:80'],
            'insurer_name' => ['nullable', 'string', 'max:150'],
            'coverage_type' => ['required', Rule::in(['tiers', 'tiers_plus', 'tous_risques'])],
            'premium_amount' => ['required', 'integer', 'min:0'],
            'deductible_amount' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        InsurancePolicy::create([
            'tenant_id' => $tenantId,
            'agency_id' => Agency::where('is_active', true)->value('id'),
            'customer_id' => $validated['customer_id'],
            'vehicle_id' => $validated['vehicle_id'],
            'policy_number' => $validated['policy_number'],
            'insurer_name' => $validated['insurer_name'] ?? $tenantContext->getTenant()?->name,
            'coverage_type' => $validated['coverage_type'],
            'premium_amount' => $validated['premium_amount'],
            'deductible_amount' => $validated['deductible_amount'] ?? 0,
            'starts_at' => $validated['starts_at'],
            'expires_at' => $validated['expires_at'],
            'status' => 'active',
            'notes' => $validated['notes'] ?? null,
        ]);

        Vehicle::where('id', $validated['vehicle_id'])->update([
            'insurance_expires_at' => $validated['expires_at'],
        ]);

        return back()->with('success', 'Police d\'assurance enregistrée et échéance véhicule mise à jour.');
    }

    public function storeClaim(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->getTenant();
        $tenantId = $tenant->id;

        $validated = $request->validate([
            'insurance_policy_id' => ['nullable', 'integer', Rule::exists('insurance_policies', 'id')->where('tenant_id', $tenantId)],
            'repair_order_id' => ['nullable', 'integer', Rule::exists('repair_orders', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId)],
            'insurance_tenant_id' => ['nullable', 'integer', Rule::exists('tenants', 'id')],
            'garage_tenant_id' => ['nullable', 'integer', Rule::exists('tenants', 'id')],
            'incident_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:2000'],
            'expert_name' => ['nullable', 'string', 'max:150'],
            'estimated_amount' => ['required', 'integer', 'min:0'],
        ]);

        InsuranceClaim::create([
            'tenant_id' => $tenantId,
            'insurance_tenant_id' => $validated['insurance_tenant_id'] ?? ($tenant->type === Tenant::TYPE_INSURANCE ? $tenantId : null),
            'garage_tenant_id' => $validated['garage_tenant_id'] ?? ($tenant->type === Tenant::TYPE_GARAGE ? $tenantId : null),
            'insurance_policy_id' => $validated['insurance_policy_id'] ?? null,
            'repair_order_id' => $validated['repair_order_id'] ?? null,
            'customer_id' => $validated['customer_id'],
            'vehicle_id' => $validated['vehicle_id'],
            'incident_date' => $validated['incident_date'],
            'description' => $validated['description'],
            'expert_name' => $validated['expert_name'] ?? null,
            'estimated_amount' => $validated['estimated_amount'],
            'status' => 'declare',
        ]);

        return back()->with('success', 'Dossier sinistre déclaré avec succès.');
    }

    public function approveClaim(
        Request $request,
        int $claimId,
        TenantContext $tenantContext,
        GreenApiService $greenApi
    ): RedirectResponse {
        $tenantId = $tenantContext->id();
        $claim = InsuranceClaim::withoutTenantScope()
            ->with(['customer', 'vehicle'])
            ->where('id', $claimId)
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                    ->orWhere('insurance_tenant_id', $tenantId)
                    ->orWhere('garage_tenant_id', $tenantId);
            })
            ->firstOrFail();

        $validated = $request->validate([
            'approved_amount' => ['required', 'integer', 'min:0'],
            'insurance_share_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'expert_name' => ['nullable', 'string', 'max:150'],
            'expert_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $approved = (int) $validated['approved_amount'];
        $share = (int) $validated['insurance_share_percent'];
        $insurancePart = (int) round(($approved * $share) / 100);
        $clientDeductible = max(0, $approved - $insurancePart);

        $claim->update([
            'approved_amount' => $approved,
            'insurance_share_percent' => $share,
            'client_deductible_amount' => $clientDeductible,
            'expert_name' => $validated['expert_name'] ?? $claim->expert_name,
            'expert_notes' => $validated['expert_notes'] ?? $claim->expert_notes,
            'status' => 'accord_prise_en_charge',
        ]);

        $phone = $claim->customer?->whatsapp_phone ?: $claim->customer?->phone;
        if ($phone && $greenApi->isConfigured()) {
            try {
                $plate = $claim->vehicle?->license_plate ?? '';
                $greenApi->sendMessage(
                    $phone,
                    "MboaCar Assurance : votre sinistre {$claim->claim_number} ({$plate}) a reçu un accord de prise en charge à {$share}% sur ".number_format($approved, 0, ',', ' ')." FCFA (franchise client : ".number_format($clientDeductible, 0, ',', ' ').' FCFA).'
                );
            } catch (\Throwable $e) {
                Log::warning('Insurance claim approval WhatsApp failed (non-blocking)', ['error' => $e->getMessage()]);
            }
        }

        return back()->with('success', 'Accord de prise en charge enregistré (franchise calculée automatiquement).');
    }

    public function updateClaimStatus(Request $request, int $claimId, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();
        $claim = InsuranceClaim::withoutTenantScope()
            ->where('id', $claimId)
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                    ->orWhere('insurance_tenant_id', $tenantId)
                    ->orWhere('garage_tenant_id', $tenantId);
            })
            ->firstOrFail();

        $validated = $request->validate([
            'status' => ['required', Rule::in(['declare', 'expertise', 'accord_prise_en_charge', 'en_reparation', 'cloture', 'refuse'])],
        ]);

        $claim->update(['status' => $validated['status']]);

        return back()->with('success', 'Statut du sinistre mis à jour.');
    }
}
