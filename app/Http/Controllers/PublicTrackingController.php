<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Quote;
use App\Models\RepairOrder;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicTrackingController extends Controller
{
    public function vehiclePassport(string $token): Response
    {
        $vehicle = Vehicle::withoutTenantScope()
            ->with(['tenant:id,name,city,phone,settings'])
            ->where('public_token', $token)
            ->firstOrFail();

        $orders = RepairOrder::withoutTenantScope()
            ->with(['tasks:id,repair_order_id,title,status,estimated_minutes'])
            ->where('vehicle_id', $vehicle->id)
            ->latest()
            ->take(20)
            ->get(['id', 'order_number', 'status', 'priority', 'diagnostic_notes', 'fault_codes', 'created_at']);

        return Inertia::render('Public/VehiclePassport', [
            'vehicle' => [
                'id' => $vehicle->id,
                'license_plate' => $vehicle->license_plate,
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'year' => $vehicle->year,
                'fuel_type' => $vehicle->fuel_type,
                'mileage' => $vehicle->mileage,
                'next_service_date' => $vehicle->next_service_date?->toDateString(),
                'next_service_mileage' => $vehicle->next_service_mileage,
                'public_token' => $vehicle->public_token,
            ],
            'garage' => $vehicle->tenant ? [
                'name' => $vehicle->tenant->name,
                'city' => $vehicle->tenant->city,
                'phone' => $vehicle->tenant->phone,
            ] : null,
            'orders' => $orders,
            'passportUrl' => url()->current(),
        ]);
    }

    public function repairOrderTracking(string $token): Response
    {
        $order = RepairOrder::withoutTenantScope()
            ->with([
                'tenant:id,name,city,phone',
                'vehicle:id,license_plate,brand,model,year,mileage,public_token',
                'customer:id,name',
                'mechanic:id,name',
                'tasks:id,repair_order_id,title,bay_name,status,estimated_minutes',
            ])
            ->where('tracking_token', $token)
            ->firstOrFail();

        $quotes = Quote::withoutTenantScope()
            ->with(['items:id,quote_id,type,description,quantity,unit_price,total_ht'])
            ->where('tenant_id', $order->tenant_id)
            ->where(function ($q) use ($order) {
                $q->where('repair_order_id', $order->id)
                    ->orWhere(function ($sub) use ($order) {
                        $sub->whereNull('repair_order_id')
                            ->where('vehicle_id', $order->vehicle_id)
                            ->where('customer_id', $order->customer_id);
                    });
            })
            ->latest()
            ->take(5)
            ->get(['id', 'quote_number', 'version', 'status', 'total_ht', 'vat_amount', 'total_ttc', 'notes', 'created_at']);

        return Inertia::render('Public/RepairOrderTracking', [
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'tracking_token' => $order->tracking_token,
                'status' => $order->status,
                'priority' => $order->priority,
                'fault_codes' => $order->fault_codes,
                'diagnostic_notes' => $order->diagnostic_notes,
                'created_at' => $order->created_at?->toIso8601String(),
                'mechanic_name' => $order->mechanic?->name,
                'vehicle' => $order->vehicle,
                'customer_name' => $order->customer?->name,
                'tasks' => $order->tasks,
            ],
            'garage' => $order->tenant ? [
                'name' => $order->tenant->name,
                'city' => $order->tenant->city,
                'phone' => $order->tenant->phone,
            ] : null,
            'quotes' => $quotes,
        ]);
    }

    public function respondQuote(Request $request, string $token, int $quoteId): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:accept,reject'],
        ]);

        $order = RepairOrder::withoutTenantScope()
            ->where('tracking_token', $token)
            ->firstOrFail();

        $quote = Quote::withoutTenantScope()
            ->where('id', $quoteId)
            ->where('tenant_id', $order->tenant_id)
            ->where('vehicle_id', $order->vehicle_id)
            ->firstOrFail();

        $newStatus = $validated['decision'] === 'accept'
            ? Quote::STATUS_ACCEPTED
            : Quote::STATUS_REFUSED;

        $quote->update([
            'status' => $newStatus,
            'validated_at' => $validated['decision'] === 'accept' ? now() : null,
        ]);

        AuditLog::record(
            action: 'quote.client_' . $validated['decision'] . 'ed',
            subject: $quote,
            tenantId: $order->tenant_id,
            metadata: ['tracking_token' => $token, 'quote_number' => $quote->quote_number],
            request: $request
        );

        $msg = $validated['decision'] === 'accept'
            ? "Devis {$quote->quote_number} accepté ! Le garage en est informé."
            : "Devis {$quote->quote_number} refusé.";

        return back()->with('success', $msg);
    }
}
