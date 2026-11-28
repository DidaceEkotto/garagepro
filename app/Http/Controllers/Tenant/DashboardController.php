<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\Invoice;
use App\Models\MarketplaceOrderItem;
use App\Models\Part;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RepairOrder;
use App\Models\TechnicalInspection;
use App\Models\Tenant;
use App\Models\VehicleReception;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();
        $tenantType = $tenant?->type ?? 'garage';

        $monthRevenue = (int) Payment::where('status', Payment::STATUS_SUCCESS)
            ->whereYear('paid_at', now()->year)
            ->whereMonth('paid_at', now()->month)
            ->sum('amount');

        $unpaidTotal = (int) Invoice::whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIAL])
            ->get()
            ->sum(fn (Invoice $inv) => $inv->balanceDue());

        $activeOrdersCount = RepairOrder::whereNotIn('status', [
            RepairOrder::STATUS_COMPLETED,
            RepairOrder::STATUS_DELIVERED,
        ])->count();

        $receptionsMonth = VehicleReception::whereYear('received_at', now()->year)
            ->whereMonth('received_at', now()->month)
            ->count();

        $lowStockCount = Part::whereColumn('quantity_on_hand', '<=', 'min_threshold')->count();
        $customersCount = Customer::count();

        // Série CA des 6 derniers mois pour le graphique en barres
        $revenueSeries = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $amount = (int) Payment::where('status', Payment::STATUS_SUCCESS)
                ->whereYear('paid_at', $date->year)
                ->whereMonth('paid_at', $date->month)
                ->sum('amount');

            $revenueSeries[] = [
                'label' => ucfirst($date->translatedFormat('M Y')),
                'amount' => $amount,
            ];
        }

        // Répartition des OR par statut
        $statusLabels = [
            RepairOrder::STATUS_PENDING => 'En attente',
            RepairOrder::STATUS_DIAGNOSTIC => 'Diagnostic',
            RepairOrder::STATUS_IN_PROGRESS => 'En cours',
            RepairOrder::STATUS_WAITING_PARTS => 'Attente pièce',
            RepairOrder::STATUS_QUALITY_CHECK => 'Contrôle qualité',
            RepairOrder::STATUS_COMPLETED => 'Terminé',
            RepairOrder::STATUS_DELIVERED => 'Livré',
        ];

        $ordersByStatus = [];
        foreach ($statusLabels as $code => $label) {
            $ordersByStatus[] = [
                'status' => $code,
                'label' => $label,
                'count' => RepairOrder::where('status', $code)->count(),
            ];
        }

        $recentOrders = RepairOrder::with(['customer', 'vehicle'])
            ->latest()
            ->take(5)
            ->get();

        $technicalKpis = $tenantType === 'centre_technique' ? [
            'totalPvs' => TechnicalInspection::count(),
            'favorableCount' => TechnicalInspection::where('verdict', 'favorable')->count(),
            'counterVisitCount' => TechnicalInspection::where('verdict', 'contre_visite')->count(),
            'immobilizedCount' => TechnicalInspection::where('verdict', 'immobilisation')->count(),
        ] : null;

        $insuranceKpis = $tenantType === 'assurance' ? [
            'activePolicies' => InsurancePolicy::where('status', 'active')
                ->whereDate('expires_at', '>=', now()->toDateString())
                ->count(),
            'openClaims' => InsuranceClaim::withoutTenantScope()
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id)->orWhere('insurance_tenant_id', $tenant->id))
                ->whereIn('status', ['declare', 'expertise'])
                ->count(),
            'approvedClaims' => InsuranceClaim::withoutTenantScope()
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id)->orWhere('insurance_tenant_id', $tenant->id))
                ->whereIn('status', ['accord_prise_en_charge', 'en_reparation', 'cloture'])
                ->count(),
            'approvedTotal' => (int) InsuranceClaim::withoutTenantScope()
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id)->orWhere('insurance_tenant_id', $tenant->id))
                ->sum('approved_amount'),
        ] : null;

        $isMarketplaceSeller = in_array($tenantType, [
            Tenant::TYPE_PARTS_SHOP,
            Tenant::TYPE_GARAGE,
            Tenant::TYPE_TECHNICAL_CENTER,
        ], true);

        $partsShopKpis = $isMarketplaceSeller ? [
            'publishedProducts' => Product::where('tenant_id', $tenant->id)->where('is_published', true)->count(),
            'totalProducts' => Product::where('tenant_id', $tenant->id)->count(),
            'lowStockProducts' => Product::where('tenant_id', $tenant->id)->where('stock_quantity', '<=', 2)->count(),
            'pendingOrders' => MarketplaceOrderItem::where('tenant_id', $tenant->id)
                ->whereIn('seller_status', ['nouvelle', 'confirmee', 'expediee'])
                ->count(),
            'deliveredOrders' => MarketplaceOrderItem::where('tenant_id', $tenant->id)
                ->where('seller_status', 'livree')
                ->count(),
            'soldUnits' => (int) MarketplaceOrderItem::where('tenant_id', $tenant->id)
                ->where('seller_status', 'livree')
                ->sum('quantity'),
            'marketplaceRevenue' => (int) MarketplaceOrderItem::where('tenant_id', $tenant->id)
                ->where('seller_status', 'livree')
                ->sum('subtotal'),
        ] : null;

        return Inertia::render('Tenant/Dashboard', [
            'tenantType' => $tenantType,
            'technicalKpis' => $technicalKpis,
            'insuranceKpis' => $insuranceKpis,
            'partsShopKpis' => $partsShopKpis,
            'kpis' => [
                'monthRevenue' => $monthRevenue,
                'unpaidTotal' => $unpaidTotal,
                'activeOrdersCount' => $activeOrdersCount,
                'receptionsMonth' => $receptionsMonth,
                'lowStockCount' => $lowStockCount,
                'customersCount' => $customersCount,
            ],
            'revenueSeries' => $revenueSeries,
            'ordersByStatus' => $ordersByStatus,
            'recentOrders' => $recentOrders,
        ]);
    }
}
