<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreAgencyRequest;
use App\Http\Requests\Tenant\UpdateAgencyRequest;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgencyController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Agency::class);

        $user = $request->user('web');
        $agencies = Agency::orderByDesc('is_main')
            ->orderBy('name')
            ->get()
            ->filter(fn (Agency $agency) => $user->hasAccessToAgency($agency->id))
            ->values();

        return Inertia::render('Tenant/Agencies/Index', [
            'agencies' => $agencies,
            'activeAgencyId' => $request->session()->get('active_agency_id', $user->default_agency_id),
            'canManage' => $user->can('agencies.manage'),
        ]);
    }

    public function store(StoreAgencyRequest $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->getTenant();
        if ($tenant && $tenant->type === \App\Models\Tenant::TYPE_PARTS_SHOP && Agency::count() >= 1) {
            return back()->with('error', 'Les comptes Vendeur de Pièces Détachées sont limités à une seule agence.');
        }

        $agency = Agency::create([
            ...$request->validated(),
            'tenant_id' => $tenantContext->id(),
            'is_main' => false,
            'is_active' => true,
        ]);

        $user = $request->user('web');
        $user->agencies()->syncWithoutDetaching([
            $agency->id => ['tenant_id' => $tenantContext->id()],
        ]);

        AuditLog::record('agency.created', subject: $agency, actor: $user, tenantId: $tenantContext->id(), request: $request);

        return back()->with('success', "L'agence « {$agency->name} » a été créée avec succès.");
    }

    public function update(UpdateAgencyRequest $request, Agency $agency, TenantContext $tenantContext): RedirectResponse
    {
        $agency->update($request->validated());

        AuditLog::record(
            'agency.updated',
            subject: $agency,
            actor: $request->user('web'),
            tenantId: $tenantContext->id(),
            request: $request
        );

        return back()->with('success', "L'agence « {$agency->name} » a été mise à jour.");
    }

    public function switchAgency(Request $request, Agency $agency): RedirectResponse
    {
        $user = $request->user('web');
        if (! $user->hasAccessToAgency($agency->id)) {
            abort(403, 'Vous n\'avez pas accès à cette agence.');
        }

        $request->session()->put('active_agency_id', $agency->id);

        return back()->with('success', "Agence active : {$agency->name}.");
    }
}
