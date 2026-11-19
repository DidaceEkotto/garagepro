<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\QualityCheck;
use App\Models\RepairOrder;
use App\Models\RepairTask;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkshopController extends Controller
{
    public function myOrders(Request $request): Response
    {
        $this->authorize('viewAny', RepairOrder::class);

        $user = $request->user('web');
        $orders = RepairOrder::with(['customer', 'vehicle', 'tasks'])
            ->when(! $user->can('repair_orders.view'), fn ($q) => $q->where('assigned_mechanic_id', $user->id))
            ->whereNotIn('status', [RepairOrder::STATUS_DELIVERED])
            ->latest()
            ->paginate(20);

        return Inertia::render('Tenant/Workshop/MyOrders', [
            'orders' => $orders,
        ]);
    }

    public function updateStatus(Request $request, RepairOrder $repairOrder): RedirectResponse
    {
        $this->authorize('view', $repairOrder);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                RepairOrder::STATUS_PENDING,
                RepairOrder::STATUS_IN_PROGRESS,
                RepairOrder::STATUS_PAUSED,
                RepairOrder::STATUS_WAITING_PARTS,
                RepairOrder::STATUS_QUALITY_CHECK,
            ])],
        ]);

        $repairOrder->update(['status' => $validated['status']]);

        return back()->with('success', 'Statut de l\'OR mis à jour.');
    }

    public function logTime(Request $request, RepairTask $task): RedirectResponse
    {
        $validated = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'status' => ['nullable', Rule::in(['a_faire', 'en_cours', 'en_pause', 'termine'])],
        ]);

        $task->increment('actual_minutes', $validated['minutes']);
        if (! empty($validated['status'])) {
            $task->update(['status' => $validated['status']]);
        }

        return back()->with('success', 'Pointage du temps enregistré.');
    }

    public function submitQualityCheck(Request $request, RepairOrder $repairOrder, TenantContext $tenantContext): RedirectResponse
    {
        if (! $request->user('web')->can('quality_control.manage')) {
            abort(403, 'Accès réservé au contrôle qualité.');
        }

        $validated = $request->validate([
            'checklist' => ['nullable', 'array'],
            'road_test_done' => ['required', 'boolean'],
            'road_test_mileage' => ['nullable', 'integer', 'min:0'],
            'passed' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        QualityCheck::create([
            'tenant_id' => $tenantContext->id(),
            'repair_order_id' => $repairOrder->id,
            'checked_by' => $request->user('web')->id,
            'checklist' => $validated['checklist'] ?? [],
            'road_test_done' => $validated['road_test_done'],
            'road_test_mileage' => $validated['road_test_mileage'] ?? null,
            'passed' => $validated['passed'],
            'notes' => $validated['notes'] ?? null,
            'checked_at' => now(),
        ]);

        // Si anomalie détectée, retour à l'atelier (en_cours) ; sinon terminé.
        $repairOrder->update([
            'status' => $validated['passed'] ? RepairOrder::STATUS_COMPLETED : RepairOrder::STATUS_IN_PROGRESS,
        ]);

        return back()->with('success', $validated['passed']
            ? 'Contrôle qualité validé.'
            : 'Anomalie signalée : véhicule retourné à l\'atelier.');
    }
}
