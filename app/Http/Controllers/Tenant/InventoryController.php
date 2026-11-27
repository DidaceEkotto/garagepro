<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Part;
use App\Models\Product;
use App\Models\RepairOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\StockService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Part::class);

        $parts = Part::with('agency')->orderBy('name')->paginate(25);
        $lowStockParts = Part::whereColumn('quantity_on_hand', '<=', 'min_threshold')
            ->orderBy('quantity_on_hand')
            ->take(15)
            ->get(['id', 'sku', 'name', 'quantity_on_hand', 'min_threshold', 'purchase_price']);

        $lowStockCount = Part::whereColumn('quantity_on_hand', '<=', 'min_threshold')->count();

        // Suggestions de réapprovisionnement 1-clic sur la Marketplace partenaire
        $lowStockRestockSuggestions = $lowStockParts->map(function (Part $part) {
            $keywords = collect(preg_split('/[\s\-\/]+/', mb_strtolower($part->name)))
                ->filter(fn ($w) => mb_strlen($w) >= 3)
                ->take(3)
                ->values();

            $matches = Product::withoutGlobalScopes()
                ->with('tenant:id,name')
                ->where('is_published', true)
                ->where('stock_quantity', '>', 0)
                ->where(function ($q) use ($part, $keywords) {
                    $q->where('sku', 'like', "%{$part->sku}%");
                    foreach ($keywords as $kw) {
                        $q->orWhere('title', 'like', "%{$kw}%");
                    }
                })
                ->orderBy('price')
                ->take(3)
                ->get(['id', 'tenant_id', 'title', 'sku', 'price', 'stock_quantity']);

            return [
                'part_id' => $part->id,
                'sku' => $part->sku,
                'name' => $part->name,
                'quantity_on_hand' => $part->quantity_on_hand,
                'min_threshold' => $part->min_threshold,
                'offers' => $matches->map(fn ($p) => [
                    'product_id' => $p->id,
                    'name' => $p->title,
                    'price' => $p->price,
                    'stock' => $p->stock_quantity,
                    'seller_name' => $p->tenant?->name ?? 'Vendeur Marketplace',
                ])->values(),
            ];
        })->values();

        return Inertia::render('Tenant/Inventory/Index', [
            'parts' => $parts,
            'lowStockCount' => $lowStockCount,
            'lowStockRestockSuggestions' => $lowStockRestockSuggestions,
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name', 'phone', 'city']),
            'repairOrders' => RepairOrder::whereNotIn('status', [RepairOrder::STATUS_DELIVERED])->orderByDesc('id')->get(['id', 'order_number']),
            'mechanics' => Employee::where('is_active', true)->orderBy('full_name')->get(['id', 'full_name', 'role_title']),
            'recentMovements' => StockMovement::with(['part', 'requestedBy'])->latest('id')->take(15)->get(),
        ]);
    }

    public function storePart(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $this->authorize('create', Part::class);

        $tenantId = $tenantContext->id();
        $validated = $request->validate([
            'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'sku' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:180'],
            'category' => ['required', Rule::in(['piece', 'consommable', 'accessoire'])],
            'condition' => ['required', Rule::in(['neuve', 'occasion', 'adaptable'])],
            'location' => ['nullable', 'string', 'max:80'],
            'quantity_on_hand' => ['required', 'integer', 'min:0'],
            'min_threshold' => ['required', 'integer', 'min:0'],
            'purchase_price' => ['required', 'integer', 'min:0'],
            'selling_price' => ['required', 'integer', 'min:0'],
        ]);

        Part::create([
            ...$validated,
            'tenant_id' => $tenantId,
            'quantity_reserved' => 0,
        ]);

        return back()->with('success', 'Pièce ajoutée au stock.');
    }

    public function storeSupplier(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $this->authorize('create', Part::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:80'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        Supplier::create([
            ...$validated,
            'tenant_id' => $tenantContext->id(),
        ]);

        return back()->with('success', 'Fournisseur enregistré.');
    }

    public function reserve(Request $request, Part $part, StockService $stockService): RedirectResponse
    {
        $this->authorize('update', $part);

        $validated = $request->validate([
            'repair_order_id' => ['required', 'integer', Rule::exists('repair_orders', 'id')->where('tenant_id', $part->tenant_id)],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $order = RepairOrder::findOrFail($validated['repair_order_id']);
        $stockService->reserveForRepairOrder($part, $validated['quantity'], $order, $request->user('web'));

        return back()->with('success', 'Pièce réservée pour l\'ordre de réparation.');
    }

    public function issue(Request $request, Part $part, StockService $stockService): RedirectResponse
    {
        $this->authorize('update', $part);

        $validated = $request->validate([
            'repair_order_id' => ['required', 'integer', Rule::exists('repair_orders', 'id')->where('tenant_id', $part->tenant_id)],
            'requested_by_employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $part->tenant_id)],
            'quantity' => ['required', 'integer', 'min:1'],
            'from_reservation' => ['nullable', 'boolean'],
        ]);

        $order = RepairOrder::findOrFail($validated['repair_order_id']);
        $mechanic = Employee::findOrFail($validated['requested_by_employee_id']);
        $stockService->issueToRepairOrder($part, $validated['quantity'], $order, $request->user('web'), (bool) ($validated['from_reservation'] ?? false), $mechanic);

        return back()->with('success', "Sortie de pièce enregistrée (demandée par {$mechanic->full_name}).");
    }

    public function transfer(Request $request, Part $part, StockService $stockService): RedirectResponse
    {
        $this->authorize('transfer', $part);

        $validated = $request->validate([
            'target_agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $part->tenant_id)],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $targetAgency = Agency::findOrFail($validated['target_agency_id']);
        $stockService->transferBetweenAgencies($part, $targetAgency, $validated['quantity'], $request->user('web'));

        return back()->with('success', 'Transfert inter-agences effectué.');
    }
}
