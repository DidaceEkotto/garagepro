<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\RepairOrder;
use App\Models\Vehicle;
use App\Models\VehicleExit;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VehicleExitController extends Controller
{
    public function store(Request $request, RepairOrder $repairOrder, TenantContext $tenantContext): RedirectResponse
    {
        $user = $request->user('web');
        if (! $user->can('vehicle_exit.manage')) {
            abort(403, 'Permission requise pour effectuer la sortie du véhicule.');
        }

        $validated = $request->validate([
            'mileage_out' => ['required', 'integer', 'min:0'],
            'belongings_returned' => ['required', 'boolean'],
            'old_parts_returned' => ['required', 'boolean'],
            'work_explained' => ['required', 'boolean'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:60'],
            'warranty_notes' => ['nullable', 'string', 'max:1000'],
            'override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $invoice = Invoice::where('repair_order_id', $repairOrder->id)
            ->where('type', Invoice::TYPE_INVOICE)
            ->latest('id')
            ->first();

        $overrideBy = null;
        if ($invoice && ! $invoice->isSettled()) {
            $canOverride = $user->can('vehicle_exit.override') && ! empty($validated['override_reason']);
            if (! $canOverride) {
                return back()->withErrors([
                    'exit' => 'Sortie bloquée : la facture associée n\'est pas encore réglée (solde dû : '.number_format($invoice->balanceDue(), 0, ',', ' ').' FCFA).',
                ]);
            }
            $overrideBy = $user->id;
        }

        $exit = DB::transaction(function () use ($validated, $repairOrder, $invoice, $user, $overrideBy, $tenantContext) {
            $exit = VehicleExit::create([
                'tenant_id' => $tenantContext->id(),
                'agency_id' => $repairOrder->agency_id,
                'repair_order_id' => $repairOrder->id,
                'invoice_id' => $invoice?->id,
                'released_by' => $user->id,
                'override_by' => $overrideBy,
                'override_reason' => $overrideBy ? $validated['override_reason'] : null,
                'mileage_out' => $validated['mileage_out'],
                'belongings_returned' => $validated['belongings_returned'],
                'old_parts_returned' => $validated['old_parts_returned'],
                'work_explained' => $validated['work_explained'],
                'warranty_months' => $validated['warranty_months'] ?? 3,
                'warranty_notes' => $validated['warranty_notes'] ?? null,
                'exited_at' => now(),
            ]);

            $repairOrder->update(['status' => RepairOrder::STATUS_DELIVERED]);

            Vehicle::where('id', $repairOrder->vehicle_id)
                ->where('mileage', '<', $validated['mileage_out'])
                ->update(['mileage' => $validated['mileage_out']]);

            return $exit;
        });

        AuditLog::record(
            action: $overrideBy ? 'vehicle_exit.override_released' : 'vehicle_exit.released',
            subject: $exit,
            actor: $user,
            tenantId: $tenantContext->id(),
            metadata: ['override_reason' => $exit->override_reason],
            request: $request
        );

        return back()->with('success', 'Bon de sortie validé. Véhicule livré au client.');
    }
}
