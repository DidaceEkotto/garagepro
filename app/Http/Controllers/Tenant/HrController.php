<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use App\Models\EmployeeAttendance;
use App\Models\Expense;
use App\Models\Payslip;
use App\Services\GreenApiService;
use App\Services\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HrController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): Response
    {
        if (! $request->user('web')->can('hr.manage')) {
            abort(403);
        }

        $tenant = $tenantContext->getTenant();
        $settings = $tenant->settings ?? [];
        $workDays = $settings['work_days'] ?? ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $workStart = $settings['work_start_time'] ?? '08:00';
        $workEnd = $settings['work_end_time'] ?? '17:30';
        $penaltyPerHour = (int) ($settings['late_penalty_per_hour'] ?? 1000);
        $lateTolerance = (int) ($settings['late_tolerance_minutes'] ?? 10);
        $overtimeRate = (int) ($settings['overtime_rate_per_hour'] ?? 1500);

        $selectedDate = $request->query('date', now()->toDateString());
        $carbonDate = Carbon::parse($selectedDate);
        $periodMonth = $carbonDate->format('Y-m');
        $dayKey = strtolower($carbonDate->format('D'));
        $isWorkDay = in_array($dayKey, $workDays, true);

        $employees = Employee::with('agency')->where('is_active', true)->orderBy('full_name')->get();

        if ($isWorkDay) {
            foreach ($employees as $employee) {
                EmployeeAttendance::firstOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'work_date' => $carbonDate->toDateString(),
                    ],
                    [
                        'tenant_id' => $tenant->id,
                        'agency_id' => $employee->agency_id,
                        'status' => 'present',
                    ]
                );
            }
        }

        $attendances = EmployeeAttendance::with('employee.agency')
            ->whereDate('work_date', $carbonDate->toDateString())
            ->get()
            ->map(fn (EmployeeAttendance $a) => [
                'id' => $a->id,
                'employee_id' => $a->employee_id,
                'employee_name' => $a->employee?->full_name,
                'role_title' => $a->employee?->role_title,
                'agency_name' => $a->employee?->agency?->name,
                'status' => $a->status ?? 'present',
                'checked_in_at' => $a->checked_in_at?->format('H:i'),
                'checked_out_at' => $a->checked_out_at?->format('H:i'),
                'late_minutes' => $a->late_minutes,
                'late_penalty' => $a->late_penalty,
                'overtime_minutes' => $a->overtime_minutes,
                'overtime_bonus' => $a->overtime_bonus,
                'notes' => $a->notes,
            ]);

        $monthStart = $carbonDate->copy()->startOfMonth()->toDateString();
        $monthEnd = $carbonDate->copy()->endOfMonth()->toDateString();

        $allMonthAttendances = EmployeeAttendance::whereBetween('work_date', [$monthStart, $monthEnd])->get();
        $allMonthAdjustments = EmployeeAdjustment::with('employee:id,full_name')
            ->where('period_month', $periodMonth)
            ->latest('id')
            ->get();
        $existingPayslips = Payslip::where('period_month', $periodMonth)->get()->keyBy('employee_id');

        $payroll = $employees->map(function (Employee $emp) use ($allMonthAttendances, $allMonthAdjustments, $existingPayslips) {
            $monthRecords = $allMonthAttendances->where('employee_id', $emp->id);
            $empAdj = $allMonthAdjustments->where('employee_id', $emp->id);

            $lateMinutes = (int) $monthRecords->sum('late_minutes');
            $latePenalties = (int) $monthRecords->sum('late_penalty');
            $commissions = (int) $monthRecords->sum('commission_earned');
            $overtimeTotal = (int) $monthRecords->sum('overtime_bonus');
            $primesTotal = (int) $empAdj->where('type', EmployeeAdjustment::TYPE_BONUS)->sum('amount');
            $advancesTotal = (int) $empAdj->where('type', EmployeeAdjustment::TYPE_ADVANCE)->sum('amount');

            $netSalary = max(
                0,
                (int) $emp->base_salary + $primesTotal + $commissions + $overtimeTotal - $latePenalties - $advancesTotal
            );

            $payslip = $existingPayslips->get($emp->id);

            return [
                'id' => $emp->id,
                'full_name' => $emp->full_name,
                'role_title' => $emp->role_title,
                'phone' => $emp->phone,
                'base_salary' => (int) $emp->base_salary,
                'primes_total' => $primesTotal,
                'commissions' => $commissions,
                'overtime_total' => $overtimeTotal,
                'late_minutes' => $lateMinutes,
                'late_penalties' => $latePenalties,
                'advances_total' => $advancesTotal,
                'net_salary' => $payslip ? $payslip->net_salary : $netSalary,
                'payslip_id' => $payslip?->id,
                'payslip_status' => $payslip?->status ?? 'non_genere',
                'payment_method' => $payslip?->payment_method,
                'paid_at' => $payslip?->paid_at?->format('d/m/Y H:i'),
                'whatsapp_sent_at' => $payslip?->whatsapp_sent_at?->format('d/m/Y H:i'),
                'attendance_counts' => [
                    'present' => $monthRecords->where('status', 'present')->count(),
                    'retard' => $monthRecords->where('status', 'retard')->count(),
                    'absent' => $monthRecords->where('status', 'absent')->count(),
                    'conge' => $monthRecords->whereIn('status', ['conge', 'permission'])->count(),
                ],
            ];
        });

        $adjustments = $allMonthAdjustments->map(fn (EmployeeAdjustment $adj) => [
            'id' => $adj->id,
            'employee_id' => $adj->employee_id,
            'employee_name' => $adj->employee?->full_name,
            'type' => $adj->type,
            'amount' => $adj->amount,
            'label' => $adj->label,
            'effective_date' => $adj->effective_date?->format('d/m/Y'),
        ])->values();

        return Inertia::render('Tenant/Hr/Index', [
            'selectedDate' => $carbonDate->toDateString(),
            'periodMonth' => $periodMonth,
            'isWorkDay' => $isWorkDay,
            'schedule' => [
                'work_start_time' => $workStart,
                'work_end_time' => $workEnd,
                'late_penalty_per_hour' => $penaltyPerHour,
                'late_tolerance_minutes' => $lateTolerance,
                'overtime_rate_per_hour' => $overtimeRate,
            ],
            'attendances' => $attendances,
            'payroll' => $payroll,
            'adjustments' => $adjustments,
            'employees' => $employees->map(fn ($e) => ['id' => $e->id, 'full_name' => $e->full_name, 'role_title' => $e->role_title]),
        ]);
    }

    public function checkIn(
        Request $request,
        EmployeeAttendance $attendance,
        TenantContext $tenantContext
    ): RedirectResponse {
        if (! $request->user('web')->can('hr.manage')) {
            abort(403);
        }

        if ($attendance->work_date->isFuture() && ! $attendance->work_date->isToday()) {
            return back()->with('error', 'Impossible de pointer une date future.');
        }

        if ($attendance->checked_in_at !== null) {
            return back()->with('error', "L'arrivée a déjà été pointée à {$attendance->checked_in_at->format('H:i')} et ne peut plus être modifiée.");
        }

        $validated = $request->validate([
            'time' => ['nullable', 'date_format:H:i'],
        ]);

        $tenant = $tenantContext->getTenant();
        $settings = $tenant->settings ?? [];
        $workStart = $settings['work_start_time'] ?? '08:00';
        $penaltyPerHour = (int) ($settings['late_penalty_per_hour'] ?? 1000);
        $tolerance = (int) ($settings['late_tolerance_minutes'] ?? 10);

        $dateStr = $attendance->work_date->toDateString();
        $arrivalTime = ! empty($validated['time'])
            ? Carbon::parse("{$dateStr} {$validated['time']}")
            : now();

        $expectedStart = Carbon::parse("{$dateStr} {$workStart}");
        $rawLateMinutes = $arrivalTime->greaterThan($expectedStart)
            ? (int) $expectedStart->diffInMinutes($arrivalTime)
            : 0;

        $lateMinutes = $rawLateMinutes > $tolerance ? $rawLateMinutes : 0;
        $latePenalty = $lateMinutes > 0
            ? (int) round(($lateMinutes / 60) * $penaltyPerHour)
            : 0;

        $attendance->update([
            'status' => $lateMinutes > 0 ? 'retard' : 'present',
            'checked_in_at' => $arrivalTime,
            'late_minutes' => $lateMinutes,
            'late_penalty' => $latePenalty,
        ]);

        $msg = $lateMinutes > 0
            ? "Arrivée enregistrée avec {$lateMinutes} min de retard (retenue : {$latePenalty} FCFA)."
            : 'Arrivée enregistrée à l\'heure.';

        return back()->with('success', $msg);
    }

    public function checkOut(
        Request $request,
        EmployeeAttendance $attendance,
        TenantContext $tenantContext
    ): RedirectResponse {
        if (! $request->user('web')->can('hr.manage')) {
            abort(403);
        }

        if ($attendance->checked_in_at === null) {
            return back()->with('error', 'Veuillez d\'abord pointer l\'heure d\'arrivée avant d\'enregistrer le départ.');
        }

        if ($attendance->checked_out_at !== null) {
            return back()->with('error', "Le départ a déjà été pointé à {$attendance->checked_out_at->format('H:i')}.");
        }

        $validated = $request->validate([
            'time' => ['nullable', 'date_format:H:i'],
        ]);

        $dateStr = $attendance->work_date->toDateString();
        $exitTime = ! empty($validated['time'])
            ? Carbon::parse("{$dateStr} {$validated['time']}")
            : now();

        if ($exitTime->lessThanOrEqualTo($attendance->checked_in_at)) {
            return back()->with('error', "L'heure de départ doit être postérieure à l'heure d'arrivée ({$attendance->checked_in_at->format('H:i')}).");
        }

        $settings = $tenantContext->getTenant()->settings ?? [];
        $workEnd = $settings['work_end_time'] ?? '17:30';
        $overtimeRate = (int) ($settings['overtime_rate_per_hour'] ?? 1500);
        $expectedEnd = Carbon::parse("{$dateStr} {$workEnd}");

        $overtimeMinutes = $exitTime->greaterThan($expectedEnd)
            ? (int) $expectedEnd->diffInMinutes($exitTime)
            : 0;
        $overtimeBonus = $overtimeMinutes >= 15
            ? (int) round(($overtimeMinutes / 60) * $overtimeRate)
            : 0;

        $attendance->update([
            'checked_out_at' => $exitTime,
            'overtime_minutes' => $overtimeMinutes,
            'overtime_bonus' => $overtimeBonus,
        ]);

        $msg = $overtimeBonus > 0
            ? "Départ enregistré (+{$overtimeMinutes} min sup = +{$overtimeBonus} FCFA)."
            : 'Heure de départ enregistrée.';

        return back()->with('success', $msg);
    }

    public function updateStatus(Request $request, EmployeeAttendance $attendance): RedirectResponse
    {
        if (! $request->user('web')->can('hr.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['present', 'retard', 'absent', 'conge', 'permission'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $updates = [
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
        ];

        if (in_array($validated['status'], ['absent', 'conge', 'permission'], true)) {
            $updates['late_minutes'] = 0;
            $updates['late_penalty'] = 0;
        }

        $attendance->update($updates);

        return back()->with('success', 'Statut de présence mis à jour.');
    }

    public function storeAdjustment(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        if (! $request->user('web')->can('hr.manage')) {
            abort(403);
        }

        $tenantId = $tenantContext->id();
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'type' => ['required', Rule::in([EmployeeAdjustment::TYPE_ADVANCE, EmployeeAdjustment::TYPE_BONUS])],
            'amount' => ['required', 'integer', 'min:100'],
            'label' => ['required', 'string', 'max:150'],
            'effective_date' => ['required', 'date'],
        ]);

        EmployeeAdjustment::create([
            'tenant_id' => $tenantId,
            'employee_id' => $validated['employee_id'],
            'type' => $validated['type'],
            'amount' => $validated['amount'],
            'label' => $validated['label'],
            'effective_date' => $validated['effective_date'],
            'period_month' => substr($validated['effective_date'], 0, 7),
            'created_by' => $request->user('web')?->id,
        ]);

        return back()->with('success', $validated['type'] === 'avance' ? 'Avance sur salaire enregistrée.' : 'Prime enregistrée.');
    }

    public function destroyAdjustment(Request $request, EmployeeAdjustment $adjustment): RedirectResponse
    {
        if (! $request->user('web')->can('hr.manage')) {
            abort(403);
        }

        $adjustment->delete();

        return back()->with('success', 'Écriture supprimée.');
    }

    public function generatePayslips(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        if (! $request->user('web')->can('hr.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'period_month' => ['required', 'date_format:Y-m'],
        ]);

        $periodMonth = $validated['period_month'];
        $carbonMonth = Carbon::createFromFormat('Y-m', $periodMonth);
        $monthStart = $carbonMonth->copy()->startOfMonth()->toDateString();
        $monthEnd = $carbonMonth->copy()->endOfMonth()->toDateString();
        $tenantId = $tenantContext->id();

        $employees = Employee::where('is_active', true)->get();
        $attendances = EmployeeAttendance::whereBetween('work_date', [$monthStart, $monthEnd])->get();
        $adjustments = EmployeeAdjustment::where('period_month', $periodMonth)->get();

        foreach ($employees as $emp) {
            $existing = Payslip::where('employee_id', $emp->id)->where('period_month', $periodMonth)->first();
            if ($existing && $existing->status === Payslip::STATUS_PAID) {
                continue;
            }

            $empAtt = $attendances->where('employee_id', $emp->id);
            $empAdj = $adjustments->where('employee_id', $emp->id);

            $primes = (int) $empAdj->where('type', EmployeeAdjustment::TYPE_BONUS)->sum('amount');
            $advances = (int) $empAdj->where('type', EmployeeAdjustment::TYPE_ADVANCE)->sum('amount');
            $commissions = (int) $empAtt->sum('commission_earned');
            $overtime = (int) $empAtt->sum('overtime_bonus');
            $lateMins = (int) $empAtt->sum('late_minutes');
            $latePenalties = (int) $empAtt->sum('late_penalty');
            $net = max(0, (int) $emp->base_salary + $primes + $commissions + $overtime - $latePenalties - $advances);

            Payslip::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'employee_id' => $emp->id,
                    'period_month' => $periodMonth,
                ],
                [
                    'base_salary' => (int) $emp->base_salary,
                    'primes_total' => $primes,
                    'commissions_total' => $commissions,
                    'overtime_total' => $overtime,
                    'late_minutes_total' => $lateMins,
                    'late_penalties_total' => $latePenalties,
                    'advances_total' => $advances,
                    'net_salary' => $net,
                    'status' => Payslip::STATUS_VALIDATED,
                ]
            );
        }

        return back()->with('success', "Bulletins de paie générés et validés pour {$periodMonth}.");
    }

    public function payPayslip(Request $request, Payslip $payslip, TenantContext $tenantContext): RedirectResponse
    {
        if (! $request->user('web')->can('hr.manage')) {
            abort(403);
        }

        if ($payslip->status === Payslip::STATUS_PAID) {
            return back()->with('warning', 'Ce bulletin est déjà marqué comme payé.');
        }

        $validated = $request->validate([
            'payment_method' => ['required', Rule::in(['especes', 'mobile_money', 'virement', 'cheque'])],
        ]);

        DB::transaction(function () use ($payslip, $validated, $tenantContext, $request) {
            $payslip->update([
                'status' => Payslip::STATUS_PAID,
                'payment_method' => $validated['payment_method'],
                'paid_at' => now(),
            ]);

            $payslip->loadMissing('employee');
            if ($payslip->net_salary > 0) {
                Expense::create([
                    'tenant_id' => $tenantContext->id(),
                    'agency_id' => $payslip->employee?->agency_id,
                    'created_by' => $request->user('web')?->id,
                    'category' => 'salaires',
                    'description' => "Paie {$payslip->period_month} — ".($payslip->employee?->full_name ?? 'Salarié')." ({$validated['payment_method']})",
                    'amount' => $payslip->net_salary,
                    'expense_date' => now()->toDateString(),
                ]);
            }
        });

        return back()->with('success', 'Salaire payé et enregistré dans les dépenses !');
    }

    public function payslipPdf(Request $request, Payslip $payslip, TenantContext $tenantContext): HttpResponse
    {
        if (! $request->user('web')->can('hr.manage')) {
            abort(403);
        }

        $payslip->loadMissing('employee');
        $pdf = Pdf::loadView('pdf.payslip', [
            'payslip' => $payslip,
            'tenant' => $tenantContext->getTenant(),
        ]);

        return $pdf->download("bulletin-paie-{$payslip->period_month}-{$payslip->id}.pdf");
    }

    public function sendPayslipWhatsapp(
        Request $request,
        Payslip $payslip,
        GreenApiService $greenApi,
        TenantContext $tenantContext
    ): RedirectResponse {
        if (! $request->user('web')->can('hr.manage')) {
            abort(403);
        }

        $payslip->loadMissing('employee');
        $phone = $payslip->employee?->phone;
        if (empty($phone)) {
            return back()->with('warning', 'Aucun numéro de téléphone enregistré pour cet employé.');
        }

        $company = $tenantContext->getTenant()?->name ?? 'MboaCar';
        $msg = "Bonjour {$payslip->employee->full_name}, votre bulletin de paie {$company} ({$payslip->period_month}) :\n"
            .'Base : '.number_format($payslip->base_salary, 0, ',', ' ')." FCFA\n"
            .'Primes + Sup + Comm. : '.number_format($payslip->primes_total + $payslip->overtime_total + $payslip->commissions_total, 0, ',', ' ')." FCFA\n"
            .'Retenues + Avances : -'.number_format($payslip->late_penalties_total + $payslip->advances_total, 0, ',', ' ')." FCFA\n"
            .'Net à payer : '.number_format($payslip->net_salary, 0, ',', ' ')." FCFA ({$payslip->status}).";

        try {
            $res = $greenApi->sendMessage($phone, $msg);
            if (! ($res['ok'] ?? false)) {
                return back()->with('warning', 'Le bulletin n\'a pas pu être envoyé sur WhatsApp pour le moment.');
            }
            $payslip->update(['whatsapp_sent_at' => now()]);

            return back()->with('success', 'Récapitulatif de paie envoyé sur WhatsApp.');
        } catch (\Throwable $e) {
            Log::warning('Payslip WhatsApp failed (non-blocking)', ['error' => $e->getMessage()]);

            return back()->with('warning', 'WhatsApp indisponible pour le moment.');
        }
    }
}
