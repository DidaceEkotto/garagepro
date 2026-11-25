<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\PlatformMessage;
use App\Models\TechnicalInspection;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Services\GreenApiService;
use App\Services\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TechnicalInspectionController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $verdict = (string) $request->query('verdict', 'all');

        $paginator = TechnicalInspection::with([
            'vehicle:id,license_plate,brand,model,customer_id',
            'customer:id,name,first_name,phone,whatsapp_phone',
            'inspector:id,name',
            'agency:id,name',
            'parentInspection:id,pv_number',
        ])
            ->when($verdict !== 'all', fn ($q) => $q->where('verdict', $verdict))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('pv_number', 'like', "%{$search}%")
                        ->orWhere('inspection_line', 'like', "%{$search}%")
                        ->orWhereHas('vehicle', fn ($vq) => $vq->where('license_plate', 'like', "%{$search}%")->orWhere('brand', 'like', "%{$search}%")->orWhere('model', 'like', "%{$search}%"))
                        ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (TechnicalInspection $pv) => [
                'id' => $pv->id,
                'pv_number' => $pv->pv_number,
                'public_token' => $pv->public_token,
                'public_url' => route('public.technical_pv.show', ['token' => $pv->public_token]),
                'pdf_url' => route('tenant.technical_inspection.pdf', ['technicalInspection' => $pv->id]),
                'agency_id' => $pv->agency_id,
                'agency_name' => $pv->agency?->name,
                'vehicle_id' => $pv->vehicle_id,
                'parent_inspection_id' => $pv->parent_inspection_id,
                'parent_pv_number' => $pv->parentInspection?->pv_number,
                'vehicle' => $pv->vehicle,
                'customer' => $pv->customer,
                'inspector' => $pv->inspector,
                'inspection_line' => $pv->inspection_line,
                'mileage' => $pv->mileage,
                'verdict' => $pv->verdict,
                'defects' => $pv->defects,
                'inspected_at' => $pv->inspected_at?->format('d/m/Y H:i'),
                'expires_at' => $pv->expires_at?->format('d/m/Y'),
                'expires_at_iso' => $pv->expires_at?->toDateString(),
                'counter_visit_deadline' => $pv->counter_visit_deadline?->format('d/m/Y'),
                'counter_visit_deadline_iso' => $pv->counter_visit_deadline?->toDateString(),
                'notes' => $pv->notes,
                'whatsapp_sent_at' => $pv->whatsapp_sent_at?->format('d/m/Y H:i'),
            ]);

        $openCounterVisits = TechnicalInspection::with('vehicle:id,license_plate')
            ->where('verdict', TechnicalInspection::VERDICT_COUNTER_VISIT)
            ->latest('id')
            ->take(50)
            ->get(['id', 'pv_number', 'vehicle_id', 'counter_visit_deadline'])
            ->map(fn (TechnicalInspection $cv) => [
                'id' => $cv->id,
                'pv_number' => $cv->pv_number,
                'vehicle_id' => $cv->vehicle_id,
                'license_plate' => $cv->vehicle?->license_plate,
                'deadline' => $cv->counter_visit_deadline?->format('d/m/Y'),
            ]);

        return Inertia::render('Tenant/TechnicalInspection/Index', [
            'pvs' => $paginator,
            'filters' => [
                'q' => $search,
                'verdict' => $verdict,
            ],
            'openCounterVisits' => $openCounterVisits,
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'vehicles' => Vehicle::with('customer:id,name,phone')->orderBy('license_plate')->get(['id', 'customer_id', 'license_plate', 'brand', 'model', 'mileage']),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'agency_id' => ['nullable', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'parent_inspection_id' => ['nullable', 'integer', Rule::exists('technical_inspections', 'id')->where('tenant_id', $tenantId)],
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId)],
            'inspection_line' => ['nullable', 'string', 'max:60'],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'verdict' => ['required', Rule::in(['favorable', 'contre_visite', 'immobilisation'])],
            'defects' => ['required', 'array', 'min:1'],
            'defects.*.category' => ['required', 'string', 'max:80'],
            'defects.*.item' => ['required', 'string', 'max:120'],
            'defects.*.severity' => ['required', Rule::in(['ok', 'mineur', 'majeur', 'critique'])],
            'defects.*.note' => ['nullable', 'string', 'max:300'],
            'expires_at' => ['nullable', 'date'],
            'counter_visit_deadline' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($coherenceError = $this->validateVerdictCoherence($validated['verdict'], $validated['defects'])) {
            return back()->withErrors(['verdict' => $coherenceError])->withInput();
        }

        $vehicle = Vehicle::findOrFail($validated['vehicle_id']);

        $pv = TechnicalInspection::create([
            'tenant_id' => $tenantId,
            'agency_id' => $validated['agency_id'] ?? Agency::where('is_active', true)->value('id'),
            'parent_inspection_id' => $validated['parent_inspection_id'] ?? null,
            'vehicle_id' => $vehicle->id,
            'customer_id' => $vehicle->customer_id,
            'inspector_id' => $request->user('web')?->id,
            'inspection_line' => $validated['inspection_line'] ?? 'Ligne 1',
            'mileage' => $validated['mileage'] ?? $vehicle->mileage,
            'verdict' => $validated['verdict'],
            'defects' => $validated['defects'],
            'expires_at' => $validated['verdict'] === 'favorable'
                ? (! empty($validated['expires_at']) ? $validated['expires_at'] : now()->addYear()->toDateString())
                : null,
            'counter_visit_deadline' => $validated['verdict'] === 'contre_visite'
                ? (! empty($validated['counter_visit_deadline']) ? $validated['counter_visit_deadline'] : now()->addDays(30)->toDateString())
                : null,
            'notes' => $validated['notes'] ?? null,
        ]);

        $vehicleUpdates = [];
        if (! empty($validated['mileage'])) {
            $vehicleUpdates['mileage'] = $validated['mileage'];
        }
        if ($validated['verdict'] === 'favorable') {
            $vehicleUpdates['technical_inspection_expires_at'] = $pv->expires_at?->toDateString() ?? now()->addYear()->toDateString();
        }
        if (! empty($vehicleUpdates)) {
            $vehicle->update($vehicleUpdates);
        }

        AuditLog::record('technical_inspection.created', subject: $pv, actor: $request->user('web'), tenantId: $tenantId, metadata: [
            'pv_number' => $pv->pv_number,
            'verdict' => $pv->verdict,
            'parent_inspection_id' => $pv->parent_inspection_id,
        ], request: $request);

        return back()->with('success', 'Procès-verbal de visite technique enregistré.');
    }

    public function update(Request $request, TechnicalInspection $technicalInspection, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'agency_id' => ['nullable', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'inspection_line' => ['nullable', 'string', 'max:60'],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'verdict' => ['required', Rule::in(['favorable', 'contre_visite', 'immobilisation'])],
            'defects' => ['required', 'array', 'min:1'],
            'defects.*.category' => ['required', 'string', 'max:80'],
            'defects.*.item' => ['required', 'string', 'max:120'],
            'defects.*.severity' => ['required', Rule::in(['ok', 'mineur', 'majeur', 'critique'])],
            'defects.*.note' => ['nullable', 'string', 'max:300'],
            'expires_at' => ['nullable', 'date'],
            'counter_visit_deadline' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($coherenceError = $this->validateVerdictCoherence($validated['verdict'], $validated['defects'])) {
            return back()->withErrors(['verdict' => $coherenceError])->withInput();
        }

        $technicalInspection->update([
            'agency_id' => $validated['agency_id'] ?? $technicalInspection->agency_id,
            'inspection_line' => $validated['inspection_line'] ?? $technicalInspection->inspection_line,
            'mileage' => $validated['mileage'] ?? $technicalInspection->mileage,
            'verdict' => $validated['verdict'],
            'defects' => $validated['defects'],
            'expires_at' => $validated['verdict'] === 'favorable'
                ? (! empty($validated['expires_at']) ? $validated['expires_at'] : now()->addYear()->toDateString())
                : null,
            'counter_visit_deadline' => $validated['verdict'] === 'contre_visite'
                ? (! empty($validated['counter_visit_deadline']) ? $validated['counter_visit_deadline'] : now()->addDays(30)->toDateString())
                : null,
            'notes' => $validated['notes'] ?? null,
        ]);

        if ($technicalInspection->vehicle) {
            $vehicleUpdates = [];
            if (! empty($validated['mileage'])) {
                $vehicleUpdates['mileage'] = $validated['mileage'];
            }
            if ($validated['verdict'] === 'favorable' && $technicalInspection->expires_at) {
                $vehicleUpdates['technical_inspection_expires_at'] = $technicalInspection->expires_at->toDateString();
            }
            if (! empty($vehicleUpdates)) {
                $technicalInspection->vehicle->update($vehicleUpdates);
            }
        }

        AuditLog::record('technical_inspection.updated', subject: $technicalInspection, actor: $request->user('web'), tenantId: $tenantId, metadata: [
            'pv_number' => $technicalInspection->pv_number,
            'verdict' => $technicalInspection->verdict,
        ], request: $request);

        return back()->with('success', "Procès-verbal {$technicalInspection->pv_number} mis à jour.");
    }

    public function pdf(TechnicalInspection $technicalInspection): HttpResponse
    {
        $technicalInspection->load(['tenant', 'agency', 'vehicle', 'customer', 'inspector', 'parentInspection']);

        $pdf = Pdf::loadView('pdf.technical-inspection-pv', [
            'pv' => $technicalInspection,
            'publicUrl' => route('public.technical_pv.show', ['token' => $technicalInspection->public_token]),
        ]);

        return $pdf->stream("pv-visite-{$technicalInspection->pv_number}.pdf");
    }

    public function publicPdf(string $token): HttpResponse
    {
        $pv = TechnicalInspection::withoutTenantScope()
            ->with([
                'tenant:id,name,city,phone',
                'agency' => fn ($q) => $q->withoutTenantScope()->select('id', 'name'),
                'vehicle' => fn ($q) => $q->withoutTenantScope()->select('id', 'license_plate', 'brand', 'model', 'vin'),
                'customer' => fn ($q) => $q->withoutTenantScope()->select('id', 'name', 'first_name'),
                'inspector' => fn ($q) => $q->withoutTenantScope()->select('id', 'name'),
                'parentInspection' => fn ($q) => $q->withoutTenantScope()->select('id', 'pv_number'),
            ])
            ->where('public_token', $token)
            ->firstOrFail();

        $pdf = Pdf::loadView('pdf.technical-inspection-pv', [
            'pv' => $pv,
            'publicUrl' => route('public.technical_pv.show', ['token' => $pv->public_token]),
        ]);

        return $pdf->stream("pv-visite-{$pv->pv_number}.pdf");
    }

    public function sendWhatsapp(
        Request $request,
        TechnicalInspection $technicalInspection,
        GreenApiService $greenApi,
        TenantContext $tenantContext
    ): RedirectResponse {
        $technicalInspection->loadMissing(['customer', 'vehicle']);
        $phone = $technicalInspection->customer?->whatsapp_phone ?: $technicalInspection->customer?->phone;

        if (empty($phone)) {
            return back()->with('warning', 'Aucun numéro WhatsApp trouvé pour ce client.');
        }

        $verdictLabels = [
            'favorable' => 'FAVORABLE (Apte)',
            'contre_visite' => 'CONTRE-VISITE REQUISE',
            'immobilisation' => 'IMMOBILISATION (Défaut critique)',
        ];

        $centerName = $tenantContext->getTenant()?->name ?? 'Centre Technique MboaCar';
        $plate = $technicalInspection->vehicle?->license_plate ?? '';
        $clientName = $technicalInspection->customer?->name ?? 'Client';
        $verdict = $verdictLabels[$technicalInspection->verdict] ?? $technicalInspection->verdict;
        $pvUrl = route('public.technical_pv.show', ['token' => $technicalInspection->public_token]);

        $majorDefects = collect($technicalInspection->defects ?? [])
            ->whereIn('severity', ['majeur', 'critique'])
            ->pluck('item')
            ->implode(', ');

        $msg = "Bonjour {$clientName}, voici le résultat de votre visite technique ({$centerName}) pour {$plate} :\n"
            ."PV N° {$technicalInspection->pv_number} — Verdict : {$verdict}";

        if ($majorDefects !== '') {
            $msg .= "\nDéfauts à corriger : {$majorDefects}";
        }
        if ($technicalInspection->expires_at) {
            $msg .= "\nValable jusqu'au : ".$technicalInspection->expires_at->format('d/m/Y');
        }
        $msg .= "\nConsulter le PV officiel : {$pvUrl}";

        try {
            $res = $greenApi->sendMessage($phone, $msg);
            if (! ($res['ok'] ?? false)) {
                return back()->with('warning', 'Le PV n\'a pas pu être envoyé sur WhatsApp pour le moment.');
            }
            $technicalInspection->update(['whatsapp_sent_at' => now()]);

            AuditLog::record('technical_inspection.whatsapp_sent', subject: $technicalInspection, actor: $request->user('web'), tenantId: $tenantContext->id(), metadata: [
                'pv_number' => $technicalInspection->pv_number,
                'phone' => $phone,
            ], request: $request);

            return back()->with('success', 'Procès-verbal envoyé au client sur WhatsApp.');
        } catch (\Throwable $e) {
            Log::warning('Technical PV WhatsApp failed (non-blocking)', ['error' => $e->getMessage()]);

            return back()->with('warning', 'WhatsApp indisponible pour le moment (PV conservé).');
        }
    }

    public function publicShow(string $token): Response
    {
        $pv = TechnicalInspection::withoutTenantScope()
            ->with([
                'tenant:id,name,city,phone',
                'parentInspection' => fn ($q) => $q->withoutTenantScope()->select('id', 'pv_number'),
                'vehicle' => fn ($q) => $q->withoutTenantScope()->select('id', 'license_plate', 'brand', 'model', 'vin'),
                'customer' => fn ($q) => $q->withoutTenantScope()->select('id', 'name', 'first_name'),
            ])
            ->where('public_token', $token)
            ->firstOrFail();

        $garages = $pv->verdict !== TechnicalInspection::VERDICT_FAVORABLE
            ? Tenant::where('type', Tenant::TYPE_GARAGE)
                ->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIALING])
                ->orderBy('name')
                ->get(['id', 'name', 'city'])
            : collect();

        $publicUrl = route('public.technical_pv.show', ['token' => $pv->public_token]);

        return Inertia::render('Public/TechnicalInspectionPv', [
            'token' => $pv->public_token,
            'publicUrl' => $publicUrl,
            'pdfUrl' => route('public.technical_pv.pdf', ['token' => $pv->public_token]),
            'garages' => $garages,
            'pv' => [
                'pv_number' => $pv->pv_number,
                'parent_pv_number' => $pv->parentInspection?->pv_number,
                'center_name' => $pv->tenant?->name ?? 'Centre Technique',
                'center_city' => $pv->tenant?->city,
                'center_phone' => $pv->tenant?->phone,
                'vehicle_plate' => $pv->vehicle?->license_plate,
                'vehicle_brand' => $pv->vehicle?->brand,
                'vehicle_model' => $pv->vehicle?->model,
                'vehicle_vin' => $pv->vehicle?->vin,
                'customer_name' => trim(($pv->customer?->name ?? '').' '.($pv->customer?->first_name ?? '')),
                'inspection_line' => $pv->inspection_line,
                'mileage' => $pv->mileage,
                'verdict' => $pv->verdict,
                'defects' => $pv->defects,
                'inspected_at' => $pv->inspected_at?->format('d/m/Y H:i'),
                'expires_at' => $pv->expires_at?->format('d/m/Y'),
                'counter_visit_deadline' => $pv->counter_visit_deadline?->format('d/m/Y'),
                'notes' => $pv->notes,
            ],
        ]);
    }

    public function requestRepairQuote(Request $request, string $token): RedirectResponse
    {
        $pv = TechnicalInspection::withoutTenantScope()
            ->with([
                'vehicle' => fn ($q) => $q->withoutTenantScope()->select('id', 'license_plate', 'brand', 'model'),
                'customer' => fn ($q) => $q->withoutTenantScope()->select('id', 'name', 'first_name', 'phone'),
            ])
            ->where('public_token', $token)
            ->firstOrFail();

        if ($pv->verdict === TechnicalInspection::VERDICT_FAVORABLE) {
            return back()->withErrors([
                'garage_tenant_id' => 'Ce procès-verbal est déjà favorable : aucune demande de devis contre-visite n\'est nécessaire.',
            ]);
        }

        $validated = $request->validate([
            'garage_tenant_id' => [
                'required',
                'integer',
                Rule::exists('tenants', 'id')
                    ->where('type', Tenant::TYPE_GARAGE)
                    ->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIALING]),
            ],
            'client_phone' => ['nullable', 'string', 'max:50'],
        ]);

        $defects = collect($pv->defects ?? [])
            ->whereIn('severity', ['majeur', 'critique'])
            ->pluck('item')
            ->implode(', ');

        $plate = $pv->vehicle?->license_plate ?? 'Véhicule';
        $clientName = trim(($pv->customer?->name ?? '').' '.($pv->customer?->first_name ?? '')) ?: 'Client';
        $phone = $validated['client_phone'] ?: ($pv->customer?->phone ?? '');

        PlatformMessage::create([
            'tenant_id' => $validated['garage_tenant_id'],
            'sender_type' => PlatformMessage::TYPE_CUSTOMER,
            'sender_name' => $clientName,
            'recipient_type' => PlatformMessage::TYPE_TENANT,
            'subject' => "Demande de devis suite à contre-visite ({$plate})",
            'body' => "PV {$pv->pv_number} — Véhicule {$plate} ({$phone}). Défauts relevés : ".($defects ?: 'Voir PV').'. Lien PV : '.route('public.technical_pv.show', ['token' => $pv->public_token]),
            'is_read' => false,
        ]);

        AuditLog::record('technical_inspection.quote_requested', subject: $pv, tenantId: (int) $validated['garage_tenant_id'], metadata: [
            'pv_number' => $pv->pv_number,
            'plate' => $plate,
        ], request: $request);

        return back()->with('success', 'Votre PV et la liste des défauts ont été transmis au garage choisi pour devis !');
    }

    private function validateVerdictCoherence(string $verdict, array $defects): ?string
    {
        $severities = collect($defects)->pluck('severity');

        if ($severities->contains('critique') && $verdict === TechnicalInspection::VERDICT_FAVORABLE) {
            return 'Un PV contenant au moins un défaut critique ne peut pas avoir un verdict Favorable.';
        }

        if ($severities->contains('majeur') && $verdict === TechnicalInspection::VERDICT_FAVORABLE) {
            return 'Un PV contenant au moins un défaut majeur ne peut pas avoir un verdict Favorable (choisissez Contre-visite ou Immobilisation).';
        }

        return null;
    }
}
