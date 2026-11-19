<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\Payment;
use App\Models\RepairOrder;
use App\Models\VehicleReception;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
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

        return Inertia::render('Tenant/Dashboard', [
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
