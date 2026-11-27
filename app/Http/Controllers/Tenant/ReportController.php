<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\CashRegisterClosure;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\RepairOrder;
use App\Models\StockMovement;
use App\Models\SubcontractIntervention;
use App\Models\VehicleReception;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        if (! $request->user('web')->can('reports.view')) {
            abort(403);
        }

        $todayRevenue = (int) Payment::where('status', Payment::STATUS_SUCCESS)
            ->whereDate('paid_at', now()->toDateString())
            ->sum('amount');

        $monthRevenue = (int) Payment::where('status', Payment::STATUS_SUCCESS)
            ->whereYear('paid_at', now()->year)
            ->whereMonth('paid_at', now()->month)
            ->sum('amount');

        $unpaidTotal = (int) Invoice::whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIAL])
            ->get()
            ->sum(fn (Invoice $inv) => $inv->balanceDue());

        $vehiclesReceivedMonth = VehicleReception::whereYear('received_at', now()->year)
            ->whereMonth('received_at', now()->month)
            ->count();

        // Analyse mensuelle sur les 6 derniers mois (du plus ancien au mois en cours)
        $monthlyComparison = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthDate = Carbon::now()->startOfMonth()->subMonths($i);
            $start = $monthDate->copy()->startOfMonth();
            $end = $monthDate->copy()->endOfMonth();

            $revenue = (int) Payment::where('status', Payment::STATUS_SUCCESS)
                ->whereBetween('paid_at', [$start, $end])
                ->sum('amount');

            $purchaseExpenses = (int) PurchaseOrder::whereIn('status', ['commandee', 'partiellement_recue', 'recue'])
                ->whereBetween('created_at', [$start, $end])
                ->sum('total_amount');

            $payrollExpenses = (int) Employee::where('is_active', true)
                ->where('created_at', '<=', $end)
                ->sum('base_salary');

            $manualExpenses = (int) Expense::whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
                ->sum('amount');

            $totalExpenses = $purchaseExpenses + $payrollExpenses + $manualExpenses;
            $netProfit = $revenue - $totalExpenses;

            $monthlyComparison[] = [
                'monthKey' => $monthDate->format('Y-m'),
                'label' => ucfirst($monthDate->translatedFormat('M Y')),
                'isCompleted' => $i > 0,
                'revenue' => $revenue,
                'purchaseExpenses' => $purchaseExpenses,
                'payrollExpenses' => $payrollExpenses,
                'manualExpenses' => $manualExpenses,
                'totalExpenses' => $totalExpenses,
                'netProfit' => $netProfit,
                'isDeficit' => $netProfit < 0,
            ];
        }

        // Comparaison mois passé (index 4) vs mois précédent (index 3)
        $lastCompleted = $monthlyComparison[4];
        $twoMonthsAgo = $monthlyComparison[3];
        $currentMonth = $monthlyComparison[5];

        $revenueDiff = $lastCompleted['revenue'] - $twoMonthsAgo['revenue'];
        $revenueChangePct = $twoMonthsAgo['revenue'] > 0
            ? round(($revenueDiff / $twoMonthsAgo['revenue']) * 100, 1)
            : ($lastCompleted['revenue'] > 0 ? 100.0 : 0.0);

        $isRevenueDecline = $lastCompleted['revenue'] < $twoMonthsAgo['revenue'];
        $isDeficitAlert = $lastCompleted['isDeficit'] || $isRevenueDecline;

        $agencies = Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $today = now()->toDateString();

        // État en temps réel de la caisse journalière par agence (Clôture Z)
        $todayClosures = CashRegisterClosure::with(['agency', 'opener', 'closer'])
            ->whereDate('closure_date', $today)
            ->get()
            ->keyBy('agency_id');

        $todayPaymentsAll = Payment::where('status', Payment::STATUS_SUCCESS)
            ->whereDate('paid_at', $today)
            ->get(['agency_id', 'payment_method', 'amount']);

        $todayExpensesAll = Expense::whereDate('expense_date', $today)
            ->get(['agency_id', 'amount']);

        $liveCashSessions = $agencies->map(function (Agency $agency) use ($todayClosures, $todayPaymentsAll, $todayExpensesAll, $today) {
            $closure = $todayClosures->get($agency->id);
            $agencyPayments = $todayPaymentsAll->where('agency_id', $agency->id);
            $cashIn = (int) $agencyPayments->where('payment_method', 'especes')->sum('amount');
            $momoIn = (int) $agencyPayments->whereIn('payment_method', ['mtn_momo', 'orange_money', 'saspay_softpay', 'saspay_checkout'])->sum('amount');
            $cardIn = (int) $agencyPayments->whereIn('payment_method', ['carte', 'cheque'])->sum('amount');
            $virementIn = (int) $agencyPayments->where('payment_method', 'virement')->sum('amount');
            $expensesCash = (int) $todayExpensesAll->where('agency_id', $agency->id)->sum('amount');
            $openingFloat = (int) ($closure?->opening_float ?? 0);
            $expectedCash = max(0, $openingFloat + $cashIn - $expensesCash);

            return [
                'agency_id' => $agency->id,
                'agency_name' => $agency->name,
                'closure_date' => $today,
                'status' => $closure?->status ?? 'not_opened',
                'opening_float' => $openingFloat,
                'cash_total' => $closure && $closure->status === CashRegisterClosure::STATUS_CLOSED ? $closure->cash_total : $cashIn,
                'mobile_money_total' => $closure && $closure->status === CashRegisterClosure::STATUS_CLOSED ? $closure->mobile_money_total : $momoIn,
                'card_bank_total' => $closure && $closure->status === CashRegisterClosure::STATUS_CLOSED ? $closure->card_bank_total : $cardIn,
                'virement_total' => $closure && $closure->status === CashRegisterClosure::STATUS_CLOSED ? $closure->virement_total : $virementIn,
                'expenses_cash_total' => $closure && $closure->status === CashRegisterClosure::STATUS_CLOSED ? $closure->expenses_cash_total : $expensesCash,
                'expected_cash' => $closure && $closure->status === CashRegisterClosure::STATUS_CLOSED ? $closure->expected_cash : $expectedCash,
                'counted_cash' => $closure?->counted_cash,
                'cash_discrepancy' => (int) ($closure?->cash_discrepancy ?? 0),
                'total_amount' => $cashIn + $momoIn + $cardIn + $virementIn,
                'opened_at' => $closure?->opened_at?->format('H:i'),
                'closed_at' => $closure?->closed_at?->format('H:i'),
                'notes' => $closure?->notes,
            ];
        })->values();

        $cashClosuresHistory = CashRegisterClosure::with(['agency', 'closer'])
            ->where('status', CashRegisterClosure::STATUS_CLOSED)
            ->latest('closure_date')
            ->take(20)
            ->get();

        // Marge nette réelle par Ordre de Réparation (20 derniers OR facturés)
        $recentInvoicedOrders = RepairOrder::with(['customer', 'vehicle', 'invoices.items'])
            ->whereHas('invoices', fn ($q) => $q->where('type', Invoice::TYPE_INVOICE)->where('status', '!=', Invoice::STATUS_CANCELLED))
            ->latest('id')
            ->take(20)
            ->get();

        $roIds = $recentInvoicedOrders->pluck('id')->all();

        $partsCostByRo = StockMovement::with('part:id,purchase_price')
            ->whereIn('repair_order_id', $roIds)
            ->where('type', 'sortie')
            ->get()
            ->groupBy('repair_order_id')
            ->map(fn ($moves) => (int) $moves->sum(fn ($m) => (int) abs($m->quantity) * (int) ($m->part?->purchase_price ?? 0)));

        $subcontractCostByRo = SubcontractIntervention::whereIn('repair_order_id', $roIds)
            ->get(['repair_order_id', 'cost_amount'])
            ->groupBy('repair_order_id')
            ->map(fn ($items) => (int) $items->sum('cost_amount'));

        $repairOrderMargins = $recentInvoicedOrders->map(function (RepairOrder $ro) use ($partsCostByRo, $subcontractCostByRo) {
            $validInvoices = $ro->invoices->where('type', Invoice::TYPE_INVOICE)->where('status', '!=', Invoice::STATUS_CANCELLED);
            $billedRevenue = (int) $validInvoices->sum('subtotal_ht');
            $laborRevenue = (int) $validInvoices->flatMap->items->where('type', 'main_oeuvre')->sum('total_price');
            $partsRevenue = (int) $validInvoices->flatMap->items->whereIn('type', ['piece', 'consommable'])->sum('total_price');
            $partsCost = (int) ($partsCostByRo->get($ro->id, 0));
            $subcontractCost = (int) ($subcontractCostByRo->get($ro->id, 0));
            $totalCost = $partsCost + $subcontractCost;
            $netMargin = $billedRevenue - $totalCost;
            $marginRate = $billedRevenue > 0 ? round(($netMargin / $billedRevenue) * 100, 1) : 0.0;

            return [
                'id' => $ro->id,
                'order_number' => $ro->order_number,
                'customer_name' => $ro->customer?->name ?? 'Client',
                'vehicle_label' => trim(($ro->vehicle?->brand ?? '').' '.($ro->vehicle?->model ?? '').' '.($ro->vehicle?->license_plate ? "({$ro->vehicle->license_plate})" : '')),
                'billed_revenue' => $billedRevenue,
                'labor_revenue' => $laborRevenue,
                'parts_revenue' => $partsRevenue,
                'parts_cost' => $partsCost,
                'subcontract_cost' => $subcontractCost,
                'net_margin' => $netMargin,
                'margin_rate' => $marginRate,
            ];
        })->values();

        return Inertia::render('Tenant/Reports/Index', [
            'stats' => [
                'todayRevenue' => $todayRevenue,
                'monthRevenue' => $monthRevenue,
                'unpaidTotal' => $unpaidTotal,
                'vehiclesReceivedMonth' => $vehiclesReceivedMonth,
                'lastCompleted' => $lastCompleted,
                'currentMonth' => $currentMonth,
                'revenueDiff' => $revenueDiff,
                'revenueChangePct' => $revenueChangePct,
                'isRevenueDecline' => $isRevenueDecline,
                'isDeficitAlert' => $isDeficitAlert,
            ],
            'monthlyComparison' => $monthlyComparison,
            'expenses' => Expense::with('agency')->latest('expense_date')->take(25)->get(),
            'agencies' => $agencies,
            'liveCashSessions' => $liveCashSessions,
            'cashClosuresHistory' => $cashClosuresHistory,
            'repairOrderMargins' => $repairOrderMargins,
        ]);
    }

    public function storeExpense(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        if (! $request->user('web')->can('reports.view')) {
            abort(403);
        }

        $data = $request->validate([
            'agency_id' => ['nullable', 'integer'],
            'category' => ['required', 'string', 'max:80'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1'],
            'expense_date' => ['required', 'date'],
        ]);

        $expense = Expense::create([
            'tenant_id' => $tenantContext->id(),
            'agency_id' => $data['agency_id'] ?? null,
            'created_by' => $request->user('web')->id,
            'category' => $data['category'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
        ]);

        AuditLog::record('expense.created', subject: $expense, actor: $request->user('web'), tenantId: $tenantContext->id(), request: $request);

        return back()->with('success', 'Dépense enregistrée et intégrée au calcul du bénéfice mensuel.');
    }
}
