<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\RepairOrder;
use App\Models\User;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkshopPlanningController extends Controller
{
    public function index(Request $request): Response
    {
        $selectedDate = $request->query('date')
            ? Carbon::parse($request->query('date'))->startOfDay()
            : now()->startOfDay();

        $weekStart = $selectedDate->copy()->startOfWeek();
        $weekEnd = $selectedDate->copy()->endOfWeek();

        $appointments = Appointment::with(['customer:id,name,phone', 'vehicle:id,license_plate,brand,model', 'mechanic:id,name'])
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$weekStart, $weekEnd])
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'date' => $a->scheduled_at->format('Y-m-d'),
                'time' => $a->scheduled_at->format('H:i'),
                'duration_minutes' => $a->duration_minutes ?? 60,
                'bay_name' => $a->bay_name ?: 'Non affecté',
                'reason' => $a->reason,
                'status' => $a->status,
                'customer' => $a->customer,
                'vehicle' => $a->vehicle,
                'mechanic' => $a->mechanic,
                'assigned_mechanic_id' => $a->assigned_mechanic_id,
            ]);

        $repairOrders = RepairOrder::with(['customer:id,name', 'vehicle:id,license_plate,brand,model', 'mechanic:id,name'])
            ->whereNotIn('status', ['livre', 'annule', 'termine'])
            ->orderByDesc('id')
            ->take(40)
            ->get(['id', 'order_number', 'customer_id', 'vehicle_id', 'assigned_mechanic_id', 'status', 'priority', 'opened_at']);

        $defaultBays = ['Pont 1', 'Pont 2', 'Pont 3', 'Pont 4', 'Zone Diagnostic'];
        $existingBays = $appointments->pluck('bay_name')->filter(fn ($b) => $b && $b !== 'Non affecté')->unique()->values()->all();
        $bays = array_values(array_unique(array_merge($defaultBays, $existingBays)));

        $weekDays = collect(range(0, 6))->map(function ($offset) use ($weekStart) {
            $d = $weekStart->copy()->addDays($offset);

            return [
                'date' => $d->format('Y-m-d'),
                'label' => $d->locale('fr')->isoFormat('ddd D MMM'),
                'isToday' => $d->isToday(),
            ];
        })->all();

        return Inertia::render('Tenant/Planning/Index', [
            'selectedDate' => $selectedDate->format('Y-m-d'),
            'weekDays' => $weekDays,
            'bays' => $bays,
            'appointments' => $appointments,
            'repairOrders' => $repairOrders,
            'mechanics' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function assignAppointment(Request $request, Appointment $appointment, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'bay_name' => ['nullable', 'string', 'max:60'],
            'assigned_mechanic_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
        ]);

        $appointment->update([
            'bay_name' => $validated['bay_name'] ?? null,
            'assigned_mechanic_id' => $validated['assigned_mechanic_id'] ?? null,
        ]);

        return back()->with('success', 'Pont et mécanicien affectés au rendez-vous.');
    }

    public function assignRepairOrder(Request $request, RepairOrder $repairOrder, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'assigned_mechanic_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
        ]);

        $repairOrder->update([
            'assigned_mechanic_id' => $validated['assigned_mechanic_id'] ?? null,
        ]);

        return back()->with('success', 'Mécanicien affecté à l\'ordre de réparation.');
    }
}
