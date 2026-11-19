<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
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
            'expenses' => Expense::with('agency')->latest('expense_date')->take(15)->get(),
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
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
