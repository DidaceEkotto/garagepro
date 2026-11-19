<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreRepairOrderRequest;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\RepairOrder;
use App\Models\RepairTask;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\NumberSequenceService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RepairOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RepairOrder::class);

        $user = $request->user('web');
        $query = RepairOrder::with(['customer', 'vehicle', 'mechanic', 'tasks']);

        if (! $user->can('repair_orders.view') && $user->can('repair_orders.view_own')) {
            $query->where('assigned_mechanic_id', $user->id);
        }

        return Inertia::render('Tenant/RepairOrders/Index', [
            'repairOrders' => $query->latest()->paginate(20),
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'vehicles' => Vehicle::orderBy('license_plate')->get(['id', 'customer_id', 'license_plate', 'brand', 'model']),
            'mechanics' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(
        StoreRepairOrderRequest $request,
        TenantContext $tenantContext,
        NumberSequenceService $sequences
    ): RedirectResponse {
        $data = $request->validated();
        $tenantId = $tenantContext->id();

        $order = DB::transaction(function () use ($data, $tenantId, $sequences) {
            $diagnosticAction = $data['diagnostic_action'] ?? null;
            $status = $diagnosticAction ? RepairOrder::STATUS_DIAGNOSTIC : RepairOrder::STATUS_PENDING;
            $diagnosticStartedAt = $diagnosticAction ? now() : null;
            $diagnosticEndedAt = $diagnosticAction === 'finish' ? now() : null;

            $order = RepairOrder::create([
                'agency_id' => $data['agency_id'],
                'customer_id' => $data['customer_id'],
                'vehicle_id' => $data['vehicle_id'],
                'reception_id' => $data['reception_id'] ?? null,
                'assigned_mechanic_id' => $data['assigned_mechanic_id'] ?? null,
                'tenant_id' => $tenantId,
                'order_number' => $sequences->nextRepairOrderNumber($tenantId),
                'status' => $status,
                'priority' => $data['priority'] ?? 'normale',
                'fault_codes' => ! empty($data['fault_codes']) ? $data['fault_codes'] : null,
                'diagnostic_notes' => $data['diagnostic_notes'] ?? null,
                'diagnostic_started_at' => $diagnosticStartedAt,
                'diagnostic_ended_at' => $diagnosticEndedAt,
            ]);

            foreach ($data['tasks'] ?? [] as $task) {
                RepairTask::create([
                    'tenant_id' => $tenantId,
                    'repair_order_id' => $order->id,
                    'title' => $task['title'],
                    'assigned_mechanic_id' => $task['assigned_mechanic_id'] ?? ($data['assigned_mechanic_id'] ?? null),
                    'bay_name' => $task['bay_name'] ?? null,
                    'estimated_minutes' => $task['estimated_minutes'],
                    'priority' => $task['priority'] ?? 'normale',
                    'status' => 'a_faire',
                ]);
            }

            return $order;
        });

        AuditLog::record('repair_order.created', subject: $order, actor: $request->user('web'), tenantId: $tenantId, request: $request);

        return back()->with('success', "Ordre de réparation {$order->order_number} créé.");
    }

    public function saveDiagnostic(Request $request, RepairOrder $repairOrder): RedirectResponse
    {
        $this->authorize('update', $repairOrder);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['start', 'finish'])],
            'diagnostic_checklist' => ['nullable', 'array'],
            'fault_codes' => ['nullable', 'array'],
            'diagnostic_notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $updates = [
            'status' => RepairOrder::STATUS_DIAGNOSTIC,
            'diagnostic_checklist' => $validated['diagnostic_checklist'] ?? $repairOrder->diagnostic_checklist,
            'fault_codes' => $validated['fault_codes'] ?? $repairOrder->fault_codes,
            'diagnostic_notes' => $validated['diagnostic_notes'] ?? $repairOrder->diagnostic_notes,
        ];

        if ($validated['action'] === 'start' && $repairOrder->diagnostic_started_at === null) {
            $updates['diagnostic_started_at'] = now();
        }

        if ($validated['action'] === 'finish') {
            $updates['diagnostic_started_at'] = $repairOrder->diagnostic_started_at ?? now();
            $updates['diagnostic_ended_at'] = now();
        }

        $repairOrder->update($updates);

        return back()->with('success', 'Diagnostic enregistré.');
    }

    public function addTask(Request $request, RepairOrder $repairOrder, TenantContext $tenantContext): RedirectResponse
    {
        $this->authorize('update', $repairOrder);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'assigned_mechanic_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantContext->id())],
            'bay_name' => ['nullable', 'string', 'max:60'],
            'subcontractor_name' => ['nullable', 'string', 'max:120'],
            'subcontractor_type' => ['nullable', Rule::in(['tolerie', 'peinture', 'electricite', 'rectification'])],
            'priority' => ['nullable', Rule::in(['normale', 'haute', 'urgente'])],
            'estimated_minutes' => ['required', 'integer', 'min:10', 'max:2400'],
            'scheduled_start_at' => ['nullable', 'date'],
            'scheduled_end_at' => ['nullable', 'date', 'after_or_equal:scheduled_start_at'],
        ]);

        RepairTask::create([
            ...$validated,
            'tenant_id' => $tenantContext->id(),
            'repair_order_id' => $repairOrder->id,
            'priority' => $validated['priority'] ?? 'normale',
            'status' => 'a_faire',
        ]);

        return back()->with('success', 'Tâche planifiée sur l\'ordre de réparation.');
    }
}
