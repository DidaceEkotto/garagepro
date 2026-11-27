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
        $orders = RepairOrder::with(['customer', 'vehicle', 'tasks.mechanic:id,name'])
            ->when(! $user->can('repair_orders.view'), fn ($q) => $q->where('assigned_mechanic_id', $user->id))
            ->whereNotIn('status', [RepairOrder::STATUS_DELIVERED])
            ->latest()
            ->paginate(20);

        // Rendement & primes mécaniciens sur les 30 derniers jours
        $recentTasks = RepairTask::with('mechanic:id,name')
            ->where('updated_at', '>=', now()->subDays(30))
            ->whereNotNull('assigned_mechanic_id')
            ->get();

        $mechanicProductivity = $recentTasks->groupBy('assigned_mechanic_id')->map(function ($tasks) {
            $mechanic = $tasks->first()->mechanic;
            $estimatedMin = (int) $tasks->sum('estimated_minutes');
            $actualMin = (int) $tasks->sum('actual_minutes');
            $efficiencyPct = $actualMin > 0 ? round(($estimatedMin / $actualMin) * 100, 1) : 0.0;
            // Prime calculée : 1 500 FCFA / heure facturée + bonus 20% si rendement >= 100%
            $baseCommission = (int) round(($estimatedMin / 60) * 1500);
            $bonus = $efficiencyPct >= 100 ? (int) round($baseCommission * 0.2) : 0;
            $explicitCommissions = (int) $tasks->sum('commission_amount');

            return [
                'mechanic_id' => $mechanic?->id,
                'mechanic_name' => $mechanic?->name ?? 'Mécanicien',
                'tasks_count' => $tasks->count(),
                'completed_count' => $tasks->where('status', 'termine')->count(),
                'estimated_hours' => round($estimatedMin / 60, 2),
                'actual_hours' => round($actualMin / 60, 2),
                'efficiency_pct' => $efficiencyPct,
                'estimated_commission' => $explicitCommissions > 0 ? $explicitCommissions : ($baseCommission + $bonus),
            ];
        })->values();

        return Inertia::render('Tenant/Workshop/MyOrders', [
            'orders' => $orders,
            'mechanicProductivity' => $mechanicProductivity,
        ]);
    }

    public function startTimer(Request $request, RepairTask $task): RedirectResponse
    {
        if (! $task->timer_started_at) {
            $task->update([
                'timer_started_at' => now(),
                'status' => 'en_cours',
            ]);
        }

        return back()->with('success', "Chrono démarré sur « {$task->title} ».");
    }

    public function pauseTimer(Request $request, RepairTask $task): RedirectResponse
    {
        $validated = $request->validate([
            'finish' => ['nullable', 'boolean'],
            'offline_elapsed_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ]);

        $elapsed = 0;
        if (isset($validated['offline_elapsed_minutes']) && (int) $validated['offline_elapsed_minutes'] > 0) {
            $elapsed = (int) $validated['offline_elapsed_minutes'];
        } elseif ($task->timer_started_at) {
            $elapsed = max(1, (int) ceil($task->timer_started_at->diffInSeconds(now()) / 60));
        }

        $finish = (bool) ($validated['finish'] ?? false);
        $task->update([
            'actual_minutes' => (int) $task->actual_minutes + $elapsed,
            'timer_started_at' => null,
            'status' => $finish ? 'termine' : 'en_pause',
        ]);

        return back()->with('success', $finish
            ? "Tâche terminée (+{$elapsed} min pointées)."
            : "Chrono mis en pause (+{$elapsed} min enregistrées).");
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
