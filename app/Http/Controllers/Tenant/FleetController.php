<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\RepairOrder;
use App\Services\NumberSequenceService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FleetController extends Controller
{
    public function index(): Response
    {
        $allCustomers = Customer::orderBy('name')->get(['id', 'name', 'company_name', 'phone', 'type', 'credit_limit', 'fleet_manager_name', 'fleet_notes']);

        $fleets = Customer::with([
            'vehicles:id,customer_id,license_plate,brand,model,mileage,cost_alert_threshold',
            'invoices:id,customer_id,vehicle_id,total_ttc,paid_amount,status,is_deferred_monthly,issued_at',
            'repairOrders:id,customer_id,vehicle_id,order_number,status,created_at',
        ])
            ->where(function ($q) {
                $q->where('type', 'entreprise')
                    ->orWhere('credit_limit', '>', 0)
                    ->orHas('vehicles', '>=', 2);
            })
            ->orderBy('name')
            ->get()
            ->map(function (Customer $c) {
                $unpaidTotal = $c->invoices
                    ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIAL])
                    ->sum(fn ($inv) => max(0, (int) $inv->total_ttc - (int) $inv->paid_amount));

                $totalBilled = $c->invoices
                    ->where('status', '!=', Invoice::STATUS_CANCELLED)
                    ->sum('total_ttc');

                $vehicleStats = $c->vehicles->map(function ($v) use ($c) {
                    $cost = $c->invoices
                        ->where('vehicle_id', $v->id)
                        ->where('status', '!=', Invoice::STATUS_CANCELLED)
                        ->sum('total_ttc');

                    $threshold = (int) ($v->cost_alert_threshold ?? 0);

                    return [
                        'id' => $v->id,
                        'license_plate' => $v->license_plate,
                        'brand' => $v->brand,
                        'model' => $v->model,
                        'mileage' => $v->mileage,
                        'total_cost' => $cost,
                        'cost_alert_threshold' => $threshold,
                        'is_cost_alert' => $threshold > 0 && $cost >= $threshold,
                    ];
                });

                return [
                    'id' => $c->id,
                    'name' => $c->company_name ?: $c->name,
                    'contact_name' => $c->name,
                    'phone' => $c->phone,
                    'type' => $c->type,
                    'credit_limit' => (int) $c->credit_limit,
                    'fleet_manager_name' => $c->fleet_manager_name,
                    'fleet_notes' => $c->fleet_notes,
                    'unpaid_total' => $unpaidTotal,
                    'total_billed' => $totalBilled,
                    'vehicles_count' => $c->vehicles->count(),
                    'vehicles' => $vehicleStats,
                    'repair_orders_count' => $c->repairOrders->count(),
                ];
            });

        return Inertia::render('Tenant/Fleets/Index', [
            'fleets' => $fleets,
            'allCustomers' => $allCustomers,
        ]);
    }

    public function updateSettings(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'company_name' => ['nullable', 'string', 'max:150'],
            'credit_limit' => ['required', 'integer', 'min:0'],
            'fleet_manager_name' => ['nullable', 'string', 'max:150'],
            'fleet_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $customer->update([
            'type' => 'entreprise',
            'company_name' => $validated['company_name'] ?? $customer->company_name,
            'credit_limit' => $validated['credit_limit'],
            'fleet_manager_name' => $validated['fleet_manager_name'] ?? null,
            'fleet_notes' => $validated['fleet_notes'] ?? null,
        ]);

        return back()->with('success', 'Paramètres de flotte B2B mis à jour.');
    }

    public function updateVehicleThreshold(Request $request, \App\Models\Vehicle $vehicle): RedirectResponse
    {
        $validated = $request->validate([
            'cost_alert_threshold' => ['required', 'integer', 'min:0'],
        ]);

        $vehicle->update(['cost_alert_threshold' => (int) $validated['cost_alert_threshold']]);

        return back()->with('success', "Seuil d'alerte coût mis à jour pour {$vehicle->license_plate}.");
    }

    public function generateMonthlyInvoice(
        Request $request,
        Customer $customer,
        TenantContext $tenantContext,
        NumberSequenceService $sequences
    ): RedirectResponse {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'flat_amount' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = $tenantContext->getTenant();
        $agencyId = Agency::where('is_active', true)->value('id');

        $invoicedRoIds = Invoice::where('customer_id', $customer->id)
            ->whereNotNull('repair_order_id')
            ->pluck('repair_order_id');

        $repairOrders = RepairOrder::with(['vehicle:id,license_plate', 'tasks'])
            ->where('customer_id', $customer->id)
            ->whereNotIn('id', $invoicedRoIds)
            ->get();

        if ($repairOrders->isEmpty() && empty($validated['flat_amount'])) {
            return back()->with('warning', 'Aucun ordre de réparation non facturé trouvé pour ce client. Vous pouvez saisir un montant forfaitaire.');
        }

        DB::transaction(function () use ($repairOrders, $customer, $tenant, $agencyId, $validated, $sequences) {
            $subtotal = 0;
            $lines = [];

            foreach ($repairOrders as $ro) {
                $roTotal = (int) $ro->tasks->sum(fn ($t) => (int) ($t->estimated_cost ?? 0));
                if ($roTotal <= 0) {
                    $roTotal = (int) ($validated['flat_amount'] ?? 0);
                }
                if ($roTotal > 0) {
                    $subtotal += $roTotal;
                    $plate = $ro->vehicle?->license_plate ? " ({$ro->vehicle->license_plate})" : '';
                    $lines[] = [
                        'description' => "OR {$ro->order_number}{$plate} — Période {$validated['month']}",
                        'quantity' => 1,
                        'unit_price' => $roTotal,
                        'total_price' => $roTotal,
                    ];
                }
            }

            if (empty($lines) && ! empty($validated['flat_amount'])) {
                $amt = (int) $validated['flat_amount'];
                $subtotal = $amt;
                $lines[] = [
                    'description' => $validated['description'] ?: "Facturation mensuelle flotte ({$validated['month']})",
                    'quantity' => 1,
                    'unit_price' => $amt,
                    'total_price' => $amt,
                ];
            }

            $vat = (int) round(($subtotal * (float) $tenant->vat_rate) / 100);
            $totalTtc = $subtotal + $vat;

            $invoice = Invoice::create([
                'tenant_id' => $tenant->id,
                'agency_id' => $agencyId,
                'customer_id' => $customer->id,
                'invoice_number' => $sequences->nextInvoiceNumber($tenant->id, Invoice::TYPE_INVOICE),
                'type' => Invoice::TYPE_INVOICE,
                'status' => Invoice::STATUS_ISSUED,
                'is_deferred_monthly' => true,
                'vat_rate' => $tenant->vat_rate,
                'subtotal_ht' => $subtotal,
                'discount_amount' => 0,
                'vat_amount' => $vat,
                'deposit_deducted' => 0,
                'total_ttc' => $totalTtc,
                'paid_amount' => 0,
                'issued_at' => now(),
                'due_at' => now()->addDays(30),
                'notes' => "Facture mensuelle groupée flotte ({$validated['month']})",
            ]);

            foreach ($lines as $line) {
                InvoiceItem::create([
                    'tenant_id' => $tenant->id,
                    'invoice_id' => $invoice->id,
                    'type' => 'main_oeuvre',
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total_price' => $line['total_price'],
                ]);
            }
        });

        return back()->with('success', 'Facture mensuelle groupée générée avec succès (consultable dans Factures).');
    }
}
