<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\PlatformMessage;
use App\Models\RepairOrder;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Services\GreenApiService;
use App\Services\SecureUploadService;
use App\Services\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InsuranceController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, SecureUploadService $uploadService): Response
    {
        $tenant = $tenantContext->getTenant();
        $tenantId = $tenant->id;
        $tenantType = $tenant->type ?? Tenant::TYPE_GARAGE;

        // Expiration automatique des polices actives dont la date est dépassée
        InsurancePolicy::where('status', 'active')
            ->whereDate('expires_at', '<', now()->toDateString())
            ->update(['status' => 'expiree']);

        $search = trim((string) $request->query('q', ''));
        $claimStatus = (string) $request->query('claim_status', 'all');
        $policyStatus = (string) $request->query('policy_status', 'all');

        $policiesQuery = InsurancePolicy::with(['customer:id,name,phone', 'vehicle:id,license_plate,brand,model'])
            ->when($policyStatus !== 'all', fn ($q) => $q->where('status', $policyStatus))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('policy_number', 'like', "%{$search}%")
                        ->orWhere('insurer_name', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('vehicle', fn ($vq) => $vq->where('license_plate', 'like', "%{$search}%"));
                });
            })
            ->latest('id');

        $policies = $policiesQuery->get()->map(fn (InsurancePolicy $p) => [
            'id' => $p->id,
            'customer_id' => $p->customer_id,
            'vehicle_id' => $p->vehicle_id,
            'policy_number' => $p->policy_number,
            'insurer_name' => $p->insurer_name,
            'coverage_type' => $p->coverage_type,
            'premium_amount' => $p->premium_amount,
            'deductible_amount' => $p->deductible_amount,
            'starts_at' => $p->starts_at?->format('d/m/Y'),
            'starts_at_iso' => $p->starts_at?->toDateString(),
            'expires_at' => $p->expires_at?->format('d/m/Y'),
            'expires_at_iso' => $p->expires_at?->toDateString(),
            'status' => $p->status,
            'notes' => $p->notes,
            'is_expiring_soon' => $p->status === 'active' && $p->expires_at && $p->expires_at->between(now()->startOfDay(), now()->addDays(15)->endOfDay()),
            'customer' => $p->customer,
            'vehicle' => $p->vehicle,
            'pdf_url' => route('tenant.insurance.policies.pdf', ['policy' => $p->id]),
        ]);

        // Passerelle cross-tenant : lever le global scope sur les relations liées pour que l'assureur et le garage voient toutes les données
        $claimsQuery = InsuranceClaim::withoutTenantScope()
            ->with([
                'customer' => fn ($q) => $q->withoutTenantScope()->select('id', 'name', 'phone', 'whatsapp_phone'),
                'vehicle' => fn ($q) => $q->withoutTenantScope()->select('id', 'license_plate', 'brand', 'model'),
                'policy' => fn ($q) => $q->withoutTenantScope()->select('id', 'policy_number', 'insurer_name', 'coverage_type'),
                'repairOrder' => fn ($q) => $q->withoutTenantScope()->select('id', 'order_number'),
                'insuranceTenant:id,name',
                'garageTenant:id,name',
            ])
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                    ->orWhere('insurance_tenant_id', $tenantId)
                    ->orWhere('garage_tenant_id', $tenantId);
            })
            ->when($claimStatus !== 'all', fn ($q) => $q->where('status', $claimStatus))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('claim_number', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('expert_name', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($cq) => $cq->withoutTenantScope()->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('vehicle', fn ($vq) => $vq->withoutTenantScope()->where('license_plate', 'like', "%{$search}%"));
                });
            })
            ->latest('id');

        $claims = $claimsQuery->get()->map(function (InsuranceClaim $c) use ($tenantId, $uploadService) {
            $photoUrls = collect($c->photos ?? [])
                ->filter(fn ($path) => is_string($path) && $path !== '')
                ->map(fn (string $path) => $uploadService->signedUrl($path, 120))
                ->values()
                ->all();

            return [
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
                'photo_urls' => $photoUrls,
                'can_approve' => $this->canTenantApproveClaim($c, $tenantId),
                'customer' => $c->customer,
                'vehicle' => $c->vehicle,
                'policy' => $c->policy,
                'repair_order' => $c->repairOrder,
                'insurance_tenant' => $c->insuranceTenant,
                'garage_tenant' => $c->garageTenant,
                'pdf_url' => route('tenant.insurance.claims.pdf', ['claimId' => $c->id]),
            ];
        });

        return Inertia::render('Tenant/Insurance/Index', [
            'tenantType' => $tenantType,
            'filters' => [
                'q' => $search,
                'claim_status' => $claimStatus,
                'policy_status' => $policyStatus,
            ],
            'policies' => $policies,
            'claims' => $claims,
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone']),
            'vehicles' => Vehicle::orderBy('license_plate')->get(['id', 'customer_id', 'license_plate', 'brand', 'model']),
            'repairOrders' => $tenantType === Tenant::TYPE_GARAGE
                ? RepairOrder::with('vehicle:id,license_plate')->latest('id')->take(60)->get(['id', 'order_number', 'vehicle_id', 'customer_id'])
                : [],
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
            'policy_number' => [
                'required',
                'string',
                'max:80',
                Rule::unique('insurance_policies', 'policy_number')->where('tenant_id', $tenantId),
            ],
            'insurer_name' => ['nullable', 'string', 'max:150'],
            'coverage_type' => ['required', Rule::in(['tiers', 'tiers_plus', 'tous_risques'])],
            'premium_amount' => ['required', 'integer', 'min:0'],
            'deductible_amount' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $vehicle = Vehicle::findOrFail($validated['vehicle_id']);
        if ((int) $vehicle->customer_id !== (int) $validated['customer_id']) {
            return back()->withErrors(['vehicle_id' => 'Le véhicule sélectionné n\'appartient pas à ce client.']);
        }

        $status = now()->startOfDay()->gt($validated['expires_at']) ? 'expiree' : 'active';

        $policy = InsurancePolicy::create([
            'tenant_id' => $tenantId,
            'agency_id' => Agency::where('is_active', true)->value('id'),
            'customer_id' => $validated['customer_id'],
            'vehicle_id' => $validated['vehicle_id'],
            'policy_number' => trim($validated['policy_number']),
            'insurer_name' => trim((string) ($validated['insurer_name'] ?? '')) ?: $tenantContext->getTenant()?->name,
            'coverage_type' => $validated['coverage_type'],
            'premium_amount' => $validated['premium_amount'],
            'deductible_amount' => $validated['deductible_amount'] ?? 0,
            'starts_at' => $validated['starts_at'],
            'expires_at' => $validated['expires_at'],
            'status' => $status,
            'notes' => $validated['notes'] ?? null,
        ]);

        if ($status === 'active') {
            $vehicle->update(['insurance_expires_at' => $validated['expires_at']]);
        }

        AuditLog::record('insurance.policy_created', subject: $policy, actor: $request->user('web'), tenantId: $tenantId, metadata: [
            'policy_number' => $policy->policy_number,
            'vehicle_id' => $vehicle->id,
        ], request: $request);

        return back()->with('success', 'Police d\'assurance enregistrée et échéance véhicule mise à jour.');
    }

    public function updatePolicy(Request $request, InsurancePolicy $policy, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'policy_number' => [
                'required',
                'string',
                'max:80',
                Rule::unique('insurance_policies', 'policy_number')->where('tenant_id', $tenantId)->ignore($policy->id),
            ],
            'insurer_name' => ['nullable', 'string', 'max:150'],
            'coverage_type' => ['required', Rule::in(['tiers', 'tiers_plus', 'tous_risques'])],
            'premium_amount' => ['required', 'integer', 'min:0'],
            'deductible_amount' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'status' => ['required', Rule::in(['active', 'expiree', 'resiliee'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $policy->update([
            'policy_number' => trim($validated['policy_number']),
            'insurer_name' => trim((string) ($validated['insurer_name'] ?? '')) ?: $policy->insurer_name,
            'coverage_type' => $validated['coverage_type'],
            'premium_amount' => $validated['premium_amount'],
            'deductible_amount' => $validated['deductible_amount'] ?? 0,
            'starts_at' => $validated['starts_at'],
            'expires_at' => $validated['expires_at'],
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
        ]);

        if ($validated['status'] === 'active') {
            Vehicle::where('id', $policy->vehicle_id)->update([
                'insurance_expires_at' => $validated['expires_at'],
            ]);
        }

        AuditLog::record('insurance.policy_updated', subject: $policy, actor: $request->user('web'), tenantId: $tenantId, metadata: [
            'status' => $policy->status,
            'expires_at' => $policy->expires_at?->toDateString(),
        ], request: $request);

        return back()->with('success', 'Police d\'assurance mise à jour.');
    }

    public function destroyPolicy(Request $request, InsurancePolicy $policy, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();
        $policyNumber = $policy->policy_number;
        $policy->delete();

        AuditLog::record('insurance.policy_deleted', actor: $request->user('web'), tenantId: $tenantId, metadata: [
            'policy_number' => $policyNumber,
        ], request: $request);

        return back()->with('success', "Police {$policyNumber} supprimée.");
    }

    public function policyPdf(InsurancePolicy $policy, TenantContext $tenantContext): HttpResponse
    {
        $policy->load(['customer', 'vehicle']);
        $tenant = $tenantContext->getTenant();

        $pdf = Pdf::loadView('pdf.insurance-policy', [
            'policy' => $policy,
            'tenant' => $tenant,
        ]);

        return $pdf->stream("attestation-police-{$policy->policy_number}.pdf");
    }

    public function storeClaim(
        Request $request,
        TenantContext $tenantContext,
        SecureUploadService $uploadService
    ): RedirectResponse {
        $tenant = $tenantContext->getTenant();
        $tenantId = $tenant->id;

        $validated = $request->validate([
            'insurance_policy_id' => ['nullable', 'integer', Rule::exists('insurance_policies', 'id')->where('tenant_id', $tenantId)],
            'repair_order_id' => ['nullable', 'integer', Rule::exists('repair_orders', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId)],
            'insurance_tenant_id' => ['nullable', 'integer', Rule::exists('tenants', 'id')->where('type', Tenant::TYPE_INSURANCE)],
            'garage_tenant_id' => ['nullable', 'integer', Rule::exists('tenants', 'id')->where('type', Tenant::TYPE_GARAGE)],
            'incident_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:2000'],
            'expert_name' => ['nullable', 'string', 'max:150'],
            'estimated_amount' => ['required', 'integer', 'min:0'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $vehicle = Vehicle::findOrFail($validated['vehicle_id']);
        if ((int) $vehicle->customer_id !== (int) $validated['customer_id']) {
            return back()->withErrors(['vehicle_id' => 'Le véhicule sélectionné n\'appartient pas à ce client.']);
        }

        $photoPaths = [];
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $file) {
                $photoPaths[] = $uploadService->storeImage($file, $tenantId, 'insurance-claims');
            }
        }

        $claim = InsuranceClaim::create([
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
            'photos' => ! empty($photoPaths) ? $photoPaths : null,
            'status' => 'declare',
        ]);

        if ($claim->insurance_tenant_id && (int) $claim->insurance_tenant_id !== (int) $tenantId) {
            PlatformMessage::create([
                'tenant_id' => $claim->insurance_tenant_id,
                'sender_type' => PlatformMessage::TYPE_TENANT,
                'sender_name' => $tenant->name,
                'recipient_type' => PlatformMessage::TYPE_TENANT,
                'subject' => "Nouveau sinistre soumis par {$tenant->name} ({$claim->claim_number})",
                'body' => "Montant estimé : ".number_format($claim->estimated_amount, 0, ',', ' ')." FCFA. {$claim->description}",
                'is_read' => false,
            ]);
        }

        AuditLog::record('insurance.claim_created', subject: $claim, actor: $request->user('web'), tenantId: $tenantId, metadata: [
            'claim_number' => $claim->claim_number,
            'estimated_amount' => $claim->estimated_amount,
            'photos_count' => count($photoPaths),
        ], request: $request);

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
            ->with([
                'customer' => fn ($q) => $q->withoutTenantScope(),
                'vehicle' => fn ($q) => $q->withoutTenantScope(),
            ])
            ->where('id', $claimId)
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                    ->orWhere('insurance_tenant_id', $tenantId)
                    ->orWhere('garage_tenant_id', $tenantId);
            })
            ->firstOrFail();

        if (! $this->canTenantApproveClaim($claim, $tenantId)) {
            abort(403, 'Seule la compagnie d\'assurance désignée peut approuver la prise en charge de ce sinistre.');
        }

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

        if ($claim->garage_tenant_id && (int) $claim->garage_tenant_id !== (int) $tenantId) {
            PlatformMessage::create([
                'tenant_id' => $claim->garage_tenant_id,
                'sender_type' => PlatformMessage::TYPE_TENANT,
                'sender_name' => $tenantContext->getTenant()?->name ?? 'Assurance',
                'recipient_type' => PlatformMessage::TYPE_TENANT,
                'subject' => "Accord de prise en charge ({$claim->claim_number})",
                'body' => "Montant approuvé : ".number_format($approved, 0, ',', ' ')." FCFA ({$share}% assurance). Franchise client : ".number_format($clientDeductible, 0, ',', ' ')." FCFA.",
                'is_read' => false,
            ]);
        }

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

        AuditLog::record('insurance.claim_approved', subject: $claim, actor: $request->user('web'), tenantId: $tenantId, metadata: [
            'claim_number' => $claim->claim_number,
            'approved_amount' => $approved,
            'insurance_share_percent' => $share,
            'client_deductible_amount' => $clientDeductible,
        ], request: $request);

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

        // Un garage ne peut pas passer lui-même un sinistre géré par un assureur externe en "accord_prise_en_charge" ou "refuse"
        if (in_array($validated['status'], ['accord_prise_en_charge', 'refuse'], true) && ! $this->canTenantApproveClaim($claim, $tenantId)) {
            abort(403, 'Seule la compagnie d\'assurance désignée peut valider ou refuser ce sinistre.');
        }

        $claim->update(['status' => $validated['status']]);

        AuditLog::record('insurance.claim_status_updated', subject: $claim, actor: $request->user('web'), tenantId: $tenantId, metadata: [
            'status' => $validated['status'],
        ], request: $request);

        return back()->with('success', 'Statut du sinistre mis à jour.');
    }

    public function claimPdf(int $claimId, TenantContext $tenantContext): HttpResponse
    {
        $tenantId = $tenantContext->id();
        $claim = InsuranceClaim::withoutTenantScope()
            ->with([
                'customer' => fn ($q) => $q->withoutTenantScope(),
                'vehicle' => fn ($q) => $q->withoutTenantScope(),
                'policy' => fn ($q) => $q->withoutTenantScope(),
                'repairOrder' => fn ($q) => $q->withoutTenantScope(),
                'insuranceTenant',
                'garageTenant',
            ])
            ->where('id', $claimId)
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                    ->orWhere('insurance_tenant_id', $tenantId)
                    ->orWhere('garage_tenant_id', $tenantId);
            })
            ->firstOrFail();

        $pdf = Pdf::loadView('pdf.insurance-claim', [
            'claim' => $claim,
            'tenant' => $tenantContext->getTenant(),
        ]);

        return $pdf->stream("sinistre-{$claim->claim_number}.pdf");
    }

    private function canTenantApproveClaim(InsuranceClaim $claim, int $tenantId): bool
    {
        if ($claim->insurance_tenant_id !== null) {
            return (int) $claim->insurance_tenant_id === $tenantId;
        }

        return (int) $claim->tenant_id === $tenantId;
    }
}
