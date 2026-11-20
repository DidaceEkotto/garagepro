<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\BulkConsumable;
use App\Models\BulkConsumableLog;
use App\Models\RepairOrder;
use App\Models\User;
use App\Models\WorkshopTool;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ToolsAndBulkController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/ToolsBulk/Index', [
            'tools' => WorkshopTool::with('borrower:id,name')->orderBy('name')->get(),
            'consumables' => BulkConsumable::with(['logs' => fn ($q) => $q->with(['user:id,name', 'repairOrder:id,order_number'])->take(5)])->orderBy('name')->get(),
            'mechanics' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'repairOrders' => RepairOrder::latest('id')->take(40)->get(['id', 'order_number']),
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeTool(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'agency_id' => ['nullable', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:60'],
            'category' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        WorkshopTool::create([
            'tenant_id' => $tenantId,
            'agency_id' => $validated['agency_id'] ?? Agency::where('is_active', true)->value('id'),
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'category' => $validated['category'] ?? 'Outillage',
            'status' => 'disponible',
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Outil ajouté au registre.');
    }

    public function borrowTool(Request $request, WorkshopTool $tool, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'borrowed_by_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
        ]);

        $tool->update([
            'status' => 'emprunte',
            'borrowed_by_user_id' => $validated['borrowed_by_user_id'],
            'borrowed_at' => now(),
        ]);

        return back()->with('success', 'Emprunt d\'outil enregistré.');
    }

    public function returnTool(WorkshopTool $tool): RedirectResponse
    {
        $tool->update([
            'status' => 'disponible',
            'borrowed_by_user_id' => null,
            'borrowed_at' => null,
        ]);

        return back()->with('success', 'Outil restitué et disponible.');
    }

    public function destroyTool(WorkshopTool $tool): RedirectResponse
    {
        $tool->delete();

        return back()->with('success', 'Outil supprimé.');
    }

    public function storeBulk(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'agency_id' => ['nullable', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['required', Rule::in(['L', 'kg', 'm'])],
            'container_capacity' => ['required', 'numeric', 'min:1'],
            'current_quantity' => ['required', 'numeric', 'min:0'],
            'alert_threshold' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'integer', 'min:0'],
        ]);

        BulkConsumable::create([
            'tenant_id' => $tenantId,
            'agency_id' => $validated['agency_id'] ?? Agency::where('is_active', true)->value('id'),
            'name' => $validated['name'],
            'unit' => $validated['unit'],
            'container_capacity' => $validated['container_capacity'],
            'current_quantity' => $validated['current_quantity'],
            'alert_threshold' => $validated['alert_threshold'],
            'unit_cost' => $validated['unit_cost'] ?? 0,
        ]);

        return back()->with('success', 'Consommable en vrac créé.');
    }

    public function logBulkMovement(Request $request, BulkConsumable $consumable, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'type' => ['required', Rule::in(['entree', 'sortie'])],
            'quantity' => ['required', 'numeric', 'min:0.1'],
            'repair_order_id' => ['nullable', 'integer', Rule::exists('repair_orders', 'id')->where('tenant_id', $tenantId)],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validated['type'] === 'sortie' && (float) $consumable->current_quantity < (float) $validated['quantity']) {
            return back()->withErrors(['quantity' => 'Quantité disponible insuffisante dans le fût/contenant.']);
        }

        DB::transaction(function () use ($consumable, $validated, $tenantId, $request) {
            $newQty = $validated['type'] === 'entree'
                ? (float) $consumable->current_quantity + (float) $validated['quantity']
                : (float) $consumable->current_quantity - (float) $validated['quantity'];

            $consumable->update(['current_quantity' => $newQty]);

            BulkConsumableLog::create([
                'tenant_id' => $tenantId,
                'bulk_consumable_id' => $consumable->id,
                'repair_order_id' => $validated['repair_order_id'] ?? null,
                'user_id' => $request->user('web')?->id,
                'type' => $validated['type'],
                'quantity' => $validated['quantity'],
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return back()->with('success', 'Mouvement de consommable enregistré.');
    }
}
