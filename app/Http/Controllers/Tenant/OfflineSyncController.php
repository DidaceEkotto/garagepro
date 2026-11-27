<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\TechnicalInspection;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OfflineSyncController extends Controller
{
    /**
     * Vérifie la vraie connectivité réseau, rafraîchit le jeton CSRF
     * et délivre le bail hors ligne de 14 jours plafonné à l'expiration de l'abonnement.
     */
    public function ping(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->getTenant();
        $now = now();
        $maxLeaseEnd = $now->copy()->addDays(14);
        $leaseEnd = $maxLeaseEnd;
        $isReadOnly = false;

        if ($tenant) {
            if (in_array($tenant->status, [Tenant::STATUS_EXPIRED, Tenant::STATUS_CANCELLED, Tenant::STATUS_SUSPENDED], true)) {
                $isReadOnly = true;
                $leaseEnd = $now->copy();
            } else {
                $subDeadline = $tenant->status === Tenant::STATUS_TRIALING
                    ? $tenant->trial_ends_at?->copy()?->addDays(2)
                    : ($tenant->grace_ends_at?->copy() ?? $tenant->subscription_ends_at?->copy()?->addDays(2));

                if ($subDeadline && $subDeadline->lessThan($leaseEnd)) {
                    $leaseEnd = $subDeadline;
                }
            }
        }

        return response()->json([
            'ok' => true,
            'server_time' => $now->toIso8601String(),
            'server_timestamp_ms' => $now->getTimestampMs(),
            'csrf_token' => csrf_token(),
            'tenant_id' => $tenant?->id,
            'offline_lease_expires_at' => $leaseEnd->toIso8601String(),
            'offline_lease_days_max' => 14,
            'is_read_only' => $isReadOnly,
        ]);
    }

    /**
     * Fournit un snapshot léger du tenant connecté pour le cache IndexedDB hors ligne.
     */
    public function bootstrap(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->id();
        if (! $tenantId) {
            return response()->json(['message' => 'Tenant introuvable.'], 403);
        }

        $customers = Customer::where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit(500)
            ->get([
                'id', 'name', 'first_name', 'phone_dial_code', 'phone', 'whatsapp_phone',
                'email', 'type', 'city', 'updated_at',
            ]);

        $vehicles = Vehicle::where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit(500)
            ->get([
                'id', 'customer_id', 'license_plate', 'brand', 'model', 'year',
                'mileage', 'technical_inspection_expires_at', 'insurance_expires_at', 'updated_at',
            ]);

        $agencies = Agency::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'is_main']);

        return response()->json([
            'ok' => true,
            'tenant_id' => $tenantId,
            'synced_at' => now()->toIso8601String(),
            'customers' => $customers,
            'vehicles' => $vehicles,
            'agencies' => $agencies,
        ]);
    }

    /**
     * Rejoue une opération enregistrée hors ligne de manière idempotente (client_uuid)
     * avec gestion des conflits "dernière modification gagnante + alerte".
     */
    public function sync(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->getTenant();
        $tenantId = $tenant?->id;
        $user = $request->user();

        if (! $tenant || ! $tenantId || ! $user) {
            return response()->json(['message' => 'Session expirée.'], 401);
        }

        $payload = $request->input('payload');
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($payload)) {
            $payload = [];
        }

        $meta = Validator::make($request->all(), [
            'client_uuid' => ['required', 'string', 'max:64'],
            'entity' => ['required', Rule::in([
                'customer',
                'vehicle',
                'technical_inspection',
                'insurance_policy',
                'insurance_claim',
            ])],
            'action' => ['required', Rule::in(['create', 'update'])],
            'record_id' => ['nullable', 'integer'],
            'base_updated_at' => ['nullable', 'string', 'max:64'],
        ])->validate();

        return match ($meta['entity']) {
            'customer' => $this->syncCustomer($request, $tenant, $meta, $payload),
            'vehicle' => $this->syncVehicle($request, $tenant, $meta, $payload),
            'technical_inspection' => $this->syncTechnicalInspection($request, $tenant, $meta, $payload),
            'insurance_policy' => $this->syncInsurancePolicy($request, $tenant, $meta, $payload),
            'insurance_claim' => $this->syncInsuranceClaim($request, $tenant, $meta, $payload),
        };
    }

    private function detectConflict(?Carbon $serverUpdatedAt, ?string $baseUpdatedAt): bool
    {
        if (! $serverUpdatedAt || empty($baseUpdatedAt)) {
            return false;
        }

        try {
            $clientBase = Carbon::parse($baseUpdatedAt);

            return $serverUpdatedAt->greaterThan($clientBase->copy()->addSeconds(1));
        } catch (\Throwable) {
            return false;
        }
    }

    private function userHasAnyPermission($user, array $permissions): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasRole('gerant') || $user->hasRole('admin')) {
            return true;
        }

        foreach ($permissions as $perm) {
            if ($user->can($perm)) {
                return true;
            }
        }

        return false;
    }

    private function syncCustomer(Request $request, Tenant $tenant, array $meta, array $payload): JsonResponse
    {
        if (! $this->userHasAnyPermission($request->user(), ['clients.manage'])) {
            return response()->json(['message' => 'Permission clients.manage requise.'], 403);
        }

        $tenantId = $tenant->id;

        if ($meta['action'] === 'create') {
            $existing = Customer::where('tenant_id', $tenantId)
                ->where('client_uuid', $meta['client_uuid'])
                ->first();

            if ($existing) {
                return response()->json([
                    'ok' => true,
                    'duplicate' => true,
                    'id' => $existing->id,
                    'label' => trim(($existing->first_name ? $existing->first_name.' ' : '').$existing->name),
                    'updated_at' => $existing->updated_at?->toIso8601String(),
                ]);
            }
        }

        $data = Validator::make($payload, [
            'name' => ['required', 'string', 'max:150'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['homme', 'femme', 'autre'])],
            'id_number' => ['nullable', 'string', 'max:100'],
            'phone_dial_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp_phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['individual', 'company', 'fleet', 'particulier', 'entreprise', 'flotte'])],
            'company_name' => ['nullable', 'string', 'max:150'],
            'profession' => ['nullable', 'string', 'max:120'],
            'date_of_birth' => ['nullable', 'date'],
            'emergency_contact' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $data['gender'] = $data['gender'] ?? 'homme';
        $data['type'] = $data['type'] ?? 'individual';
        if (empty($data['whatsapp_phone']) && ! empty($data['phone'])) {
            $data['whatsapp_phone'] = $data['phone'];
        }

        if ($meta['action'] === 'create') {
            $customer = Customer::create(array_merge($data, [
                'tenant_id' => $tenantId,
                'agency_id' => $request->user()->default_agency_id,
                'client_uuid' => $meta['client_uuid'],
            ]));

            return response()->json([
                'ok' => true,
                'id' => $customer->id,
                'label' => trim(($customer->first_name ? $customer->first_name.' ' : '').$customer->name),
                'updated_at' => $customer->updated_at?->toIso8601String(),
                'conflict' => false,
            ], 201);
        }

        $customer = Customer::where('tenant_id', $tenantId)->find($meta['record_id'] ?? 0);
        if (! $customer) {
            return response()->json(['message' => 'Client introuvable pour mise à jour.'], 404);
        }

        $conflict = $this->detectConflict($customer->updated_at, $meta['base_updated_at'] ?? null);
        $customer->update($data);

        return response()->json([
            'ok' => true,
            'id' => $customer->id,
            'label' => trim(($customer->first_name ? $customer->first_name.' ' : '').$customer->name),
            'updated_at' => $customer->fresh()->updated_at?->toIso8601String(),
            'conflict' => $conflict,
            'conflict_message' => $conflict
                ? "Conflit résolu (dernière modification gagnante) : le client « {$customer->name} » avait été modifié sur un autre poste avant votre reconnexion."
                : null,
        ]);
    }

    private function syncVehicle(Request $request, Tenant $tenant, array $meta, array $payload): JsonResponse
    {
        if (! $this->userHasAnyPermission($request->user(), ['vehicles.manage', 'clients.manage'])) {
            return response()->json(['message' => 'Permission vehicles.manage requise.'], 403);
        }

        $tenantId = $tenant->id;

        if ($meta['action'] === 'create') {
            $existing = Vehicle::where('tenant_id', $tenantId)
                ->where('client_uuid', $meta['client_uuid'])
                ->first();

            if ($existing) {
                return response()->json([
                    'ok' => true,
                    'duplicate' => true,
                    'id' => $existing->id,
                    'label' => $existing->license_plate,
                    'updated_at' => $existing->updated_at?->toIso8601String(),
                ]);
            }
        }

        $data = Validator::make($payload, [
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'license_plate' => ['required', 'string', 'max:30'],
            'brand' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:80'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'vin' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:40'],
            'fuel_type' => ['nullable', 'string', 'max:40'],
            'transmission' => ['nullable', 'string', 'max:40'],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'next_service_date' => ['nullable', 'date'],
            'technical_inspection_expires_at' => ['nullable', 'date'],
            'insurance_expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $data['license_plate'] = strtoupper(trim($data['license_plate']));

        if ($meta['action'] === 'create') {
            $vehicle = Vehicle::create(array_merge($data, [
                'tenant_id' => $tenantId,
                'client_uuid' => $meta['client_uuid'],
            ]));

            return response()->json([
                'ok' => true,
                'id' => $vehicle->id,
                'label' => $vehicle->license_plate,
                'updated_at' => $vehicle->updated_at?->toIso8601String(),
                'conflict' => false,
            ], 201);
        }

        $vehicle = Vehicle::where('tenant_id', $tenantId)->find($meta['record_id'] ?? 0);
        if (! $vehicle) {
            return response()->json(['message' => 'Véhicule introuvable pour mise à jour.'], 404);
        }

        $conflict = $this->detectConflict($vehicle->updated_at, $meta['base_updated_at'] ?? null);
        $vehicle->update($data);

        return response()->json([
            'ok' => true,
            'id' => $vehicle->id,
            'label' => $vehicle->license_plate,
            'updated_at' => $vehicle->fresh()->updated_at?->toIso8601String(),
            'conflict' => $conflict,
            'conflict_message' => $conflict
                ? "Conflit résolu (dernière modification gagnante) : le véhicule « {$vehicle->license_plate} » avait été modifié sur un autre poste."
                : null,
        ]);
    }

    private function syncTechnicalInspection(Request $request, Tenant $tenant, array $meta, array $payload): JsonResponse
    {
        if ($tenant->type !== Tenant::TYPE_TECHNICAL_CENTER) {
            return response()->json(['message' => 'Module réservé aux Centres de Visite Technique.'], 403);
        }

        if (! $this->userHasAnyPermission($request->user(), ['clients.manage', 'vehicles.manage', 'receptions.manage', 'inspections.manage'])) {
            return response()->json(['message' => 'Permission visite technique requise.'], 403);
        }

        $tenantId = $tenant->id;

        if ($meta['action'] === 'create') {
            $existing = TechnicalInspection::where('tenant_id', $tenantId)
                ->where('client_uuid', $meta['client_uuid'])
                ->first();

            if ($existing) {
                return response()->json([
                    'ok' => true,
                    'duplicate' => true,
                    'id' => $existing->id,
                    'label' => $existing->pv_number,
                    'updated_at' => $existing->updated_at?->toIso8601String(),
                ]);
            }
        }

        $data = Validator::make($payload, [
            'vehicle_id' => [
                'required',
                'integer',
                Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId),
            ],
            'parent_inspection_id' => [
                'nullable',
                'integer',
                Rule::exists('technical_inspections', 'id')->where('tenant_id', $tenantId),
            ],
            'inspection_line' => ['nullable', 'string', 'max:60'],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'verdict' => ['required', Rule::in([
                TechnicalInspection::VERDICT_FAVORABLE,
                TechnicalInspection::VERDICT_COUNTER_VISIT,
                TechnicalInspection::VERDICT_IMMOBILIZED,
            ])],
            'defects' => ['nullable', 'array'],
            'expires_at' => ['nullable', 'date'],
            'counter_visit_deadline' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ])->validate();

        $vehicle = Vehicle::where('tenant_id', $tenantId)->findOrFail($data['vehicle_id']);
        $data['defects'] = $data['defects'] ?? [];

        if ($data['verdict'] === TechnicalInspection::VERDICT_FAVORABLE) {
            $data['counter_visit_deadline'] = null;
        } else {
            $data['expires_at'] = null;
        }

        if ($meta['action'] === 'create') {
            $pv = TechnicalInspection::create(array_merge($data, [
                'tenant_id' => $tenantId,
                'agency_id' => $request->user()->default_agency_id,
                'customer_id' => $vehicle->customer_id,
                'inspector_id' => $request->user()->id,
                'inspected_at' => now(),
                'client_uuid' => $meta['client_uuid'],
            ]));

            if ($pv->verdict === TechnicalInspection::VERDICT_FAVORABLE && $pv->expires_at) {
                $vehicle->update(['technical_inspection_expires_at' => $pv->expires_at]);
            }

            return response()->json([
                'ok' => true,
                'id' => $pv->id,
                'label' => $pv->pv_number,
                'updated_at' => $pv->updated_at?->toIso8601String(),
                'conflict' => false,
            ], 201);
        }

        $pv = TechnicalInspection::where('tenant_id', $tenantId)->find($meta['record_id'] ?? 0);
        if (! $pv) {
            return response()->json(['message' => 'PV de visite technique introuvable.'], 404);
        }

        $conflict = $this->detectConflict($pv->updated_at, $meta['base_updated_at'] ?? null);
        $pv->update($data);

        if ($pv->verdict === TechnicalInspection::VERDICT_FAVORABLE && $pv->expires_at) {
            $vehicle->update(['technical_inspection_expires_at' => $pv->expires_at]);
        }

        return response()->json([
            'ok' => true,
            'id' => $pv->id,
            'label' => $pv->pv_number,
            'updated_at' => $pv->fresh()->updated_at?->toIso8601String(),
            'conflict' => $conflict,
            'conflict_message' => $conflict
                ? "Conflit résolu (dernière modification gagnante) : le PV « {$pv->pv_number} » avait été modifié sur un autre poste."
                : null,
        ]);
    }

    private function syncInsurancePolicy(Request $request, Tenant $tenant, array $meta, array $payload): JsonResponse
    {
        if (! in_array($tenant->type, [Tenant::TYPE_INSURANCE, Tenant::TYPE_GARAGE], true)) {
            return response()->json(['message' => 'Module réservé aux Assurances et Garages.'], 403);
        }

        if (! $this->userHasAnyPermission($request->user(), ['clients.manage', 'quotes.manage', 'invoices.manage'])) {
            return response()->json(['message' => 'Permission assurances requise.'], 403);
        }

        $tenantId = $tenant->id;

        if ($meta['action'] === 'create') {
            $existing = InsurancePolicy::where('tenant_id', $tenantId)
                ->where('client_uuid', $meta['client_uuid'])
                ->first();

            if ($existing) {
                return response()->json([
                    'ok' => true,
                    'duplicate' => true,
                    'id' => $existing->id,
                    'label' => $existing->policy_number,
                    'updated_at' => $existing->updated_at?->toIso8601String(),
                ]);
            }
        }

        $data = Validator::make($payload, [
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'vehicle_id' => [
                'required',
                'integer',
                Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId),
            ],
            'policy_number' => ['required', 'string', 'max:80'],
            'insurer_name' => ['required', 'string', 'max:120'],
            'coverage_type' => ['required', 'string', 'max:60'],
            'premium_amount' => ['nullable', 'integer', 'min:0'],
            'deductible_amount' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'status' => ['nullable', Rule::in(['active', 'expired', 'suspended', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $data['status'] = $data['status'] ?? 'active';
        $data['premium_amount'] = (int) ($data['premium_amount'] ?? 0);
        $data['deductible_amount'] = (int) ($data['deductible_amount'] ?? 0);

        $vehicle = Vehicle::where('tenant_id', $tenantId)->findOrFail($data['vehicle_id']);

        if ($meta['action'] === 'create') {
            $policy = InsurancePolicy::create(array_merge($data, [
                'tenant_id' => $tenantId,
                'agency_id' => $request->user()->default_agency_id,
                'client_uuid' => $meta['client_uuid'],
            ]));

            if ($policy->expires_at) {
                $vehicle->update(['insurance_expires_at' => $policy->expires_at]);
            }

            return response()->json([
                'ok' => true,
                'id' => $policy->id,
                'label' => $policy->policy_number,
                'updated_at' => $policy->updated_at?->toIso8601String(),
                'conflict' => false,
            ], 201);
        }

        $policy = InsurancePolicy::where('tenant_id', $tenantId)->find($meta['record_id'] ?? 0);
        if (! $policy) {
            return response()->json(['message' => 'Police d\'assurance introuvable.'], 404);
        }

        $conflict = $this->detectConflict($policy->updated_at, $meta['base_updated_at'] ?? null);
        $policy->update($data);

        if ($policy->expires_at) {
            $vehicle->update(['insurance_expires_at' => $policy->expires_at]);
        }

        return response()->json([
            'ok' => true,
            'id' => $policy->id,
            'label' => $policy->policy_number,
            'updated_at' => $policy->fresh()->updated_at?->toIso8601String(),
            'conflict' => $conflict,
            'conflict_message' => $conflict
                ? "Conflit résolu (dernière modification gagnante) : la police « {$policy->policy_number} » avait été modifiée sur un autre poste."
                : null,
        ]);
    }

    private function syncInsuranceClaim(Request $request, Tenant $tenant, array $meta, array $payload): JsonResponse
    {
        if (! in_array($tenant->type, [Tenant::TYPE_INSURANCE, Tenant::TYPE_GARAGE], true)) {
            return response()->json(['message' => 'Module réservé aux Assurances et Garages.'], 403);
        }

        if (! $this->userHasAnyPermission($request->user(), ['clients.manage', 'quotes.manage', 'invoices.manage'])) {
            return response()->json(['message' => 'Permission sinistres requise.'], 403);
        }

        $tenantId = $tenant->id;

        if ($meta['action'] === 'create') {
            $existing = InsuranceClaim::where('tenant_id', $tenantId)
                ->where('client_uuid', $meta['client_uuid'])
                ->first();

            if ($existing) {
                return response()->json([
                    'ok' => true,
                    'duplicate' => true,
                    'id' => $existing->id,
                    'label' => $existing->claim_number,
                    'updated_at' => $existing->updated_at?->toIso8601String(),
                ]);
            }
        }

        $data = Validator::make($payload, [
            'insurance_policy_id' => [
                'nullable',
                'integer',
                Rule::exists('insurance_policies', 'id')->where('tenant_id', $tenantId),
            ],
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'vehicle_id' => [
                'required',
                'integer',
                Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId),
            ],
            'incident_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:3000'],
            'expert_name' => ['nullable', 'string', 'max:120'],
            'estimated_amount' => ['nullable', 'integer', 'min:0'],
            'garage_tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'insurance_tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'expert_notes' => ['nullable', 'string', 'max:3000'],
        ])->validate();

        // Validation des photos transmises en multipart/form-data (Blobs stockés dans IndexedDB)
        $storedPhotos = [];
        if ($request->hasFile('photos')) {
            $request->validate([
                'photos' => ['array'],
                'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ]);

            foreach ($request->file('photos') as $file) {
                $storedPhotos[] = $file->store("insurance/claims/{$tenantId}", 'public');
            }
        }

        $claim = InsuranceClaim::create(array_merge($data, [
            'tenant_id' => $tenantId,
            'client_uuid' => $meta['client_uuid'],
            'insurance_tenant_id' => $tenant->type === Tenant::TYPE_INSURANCE
                ? $tenantId
                : ($data['insurance_tenant_id'] ?? null),
            'garage_tenant_id' => $tenant->type === Tenant::TYPE_GARAGE
                ? $tenantId
                : ($data['garage_tenant_id'] ?? null),
            'estimated_amount' => (int) ($data['estimated_amount'] ?? 0),
            'status' => 'declared',
            'photos' => $storedPhotos,
        ]));

        return response()->json([
            'ok' => true,
            'id' => $claim->id,
            'label' => $claim->claim_number,
            'photos_count' => count($storedPhotos),
            'updated_at' => $claim->updated_at?->toIso8601String(),
            'conflict' => false,
        ], 201);
    }
}
