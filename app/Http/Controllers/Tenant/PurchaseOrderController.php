<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Mail\PurchaseOrderMail;
use App\Models\Agency;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\StockService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $orders = PurchaseOrder::with(['supplier', 'agency', 'items'])
            ->latest()
            ->paginate(20);

        return Inertia::render('Tenant/PurchaseOrders/Index', [
            'purchaseOrders' => $orders,
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'parts' => Part::orderBy('name')->get(['id', 'sku', 'name', 'purchase_price']),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $tenantId = $tenantContext->id();
        $validated = $request->validate([
            'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'expected_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.part_id' => ['nullable', 'integer', Rule::exists('parts', 'id')->where('tenant_id', $tenantId)],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity_ordered' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'integer', 'min:0'],
        ]);

        $po = DB::transaction(function () use ($validated, $tenantId) {
            $total = collect($validated['items'])->sum(fn ($i) => (int) $i['quantity_ordered'] * (int) $i['unit_cost']);
            $poNumber = 'BC-'.now()->year.'-'.str_pad((string) (PurchaseOrder::count() + 1), 5, '0', STR_PAD_LEFT);

            $po = PurchaseOrder::create([
                'tenant_id' => $tenantId,
                'agency_id' => $validated['agency_id'],
                'supplier_id' => $validated['supplier_id'],
                'po_number' => $poNumber,
                'status' => PurchaseOrder::STATUS_SENT,
                'ordered_at' => now(),
                'expected_at' => $validated['expected_at'] ?? null,
                'total_amount' => $total,
            ]);

            foreach ($validated['items'] as $item) {
                PurchaseOrderItem::create([
                    'tenant_id' => $tenantId,
                    'purchase_order_id' => $po->id,
                    'part_id' => $item['part_id'] ?? null,
                    'description' => $item['description'],
                    'quantity_ordered' => $item['quantity_ordered'],
                    'quantity_received' => 0,
                    'unit_cost' => $item['unit_cost'],
                ]);
            }

            return $po;
        });

        $po->load(['supplier', 'agency', 'items']);
        if (! empty($po->supplier?->email)) {
            Mail::to($po->supplier->email)->queue(new PurchaseOrderMail($po, isReminder: false));
        }

        return back()->with('success', 'Bon de commande fournisseur créé et envoyé par e-mail.');
    }

    public function remind(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('update', $purchaseOrder);

        $purchaseOrder->load(['supplier', 'agency', 'items']);
        if (empty($purchaseOrder->supplier?->email)) {
            return back()->with('error', 'Ce fournisseur ne possède pas d\'adresse e-mail.');
        }

        Mail::to($purchaseOrder->supplier->email)->queue(new PurchaseOrderMail($purchaseOrder, isReminder: true));

        return back()->with('success', "Relance envoyée par e-mail à {$purchaseOrder->supplier->name}.");
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder, StockService $stockService): RedirectResponse
    {
        $this->authorize('update', $purchaseOrder);

        $validated = $request->validate([
            'received' => ['required', 'array'],
            'received.*' => ['integer', 'min:0'],
        ]);

        $stockService->receivePurchaseOrder($purchaseOrder, $validated['received'], $request->user('web'));

        return back()->with('success', 'Réception fournisseur enregistrée en stock.');
    }
}
