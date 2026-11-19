<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $selectedDate = $request->query('date', now()->toDateString());
        $carbonDate = Carbon::parse($selectedDate);
        $dayKey = strtolower($carbonDate->format('D')); // mon, tue, ...
        $isWorkDay = in_array($dayKey, $workDays, true);

        $employees = Employee::with('agency')->where('is_active', true)->orderBy('full_name')->get();

        // Génère automatiquement la fiche de pointage du jour si c'est un jour travaillé configuré
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
                'checked_in_at' => $a->checked_in_at?->format('H:i'),
                'checked_out_at' => $a->checked_out_at?->format('H:i'),
                'late_minutes' => $a->late_minutes,
                'late_penalty' => $a->late_penalty,
            ]);

        // Calcul de la paie du mois sélectionné avec retenues automatiques de retard
        $monthStart = $carbonDate->copy()->startOfMonth()->toDateString();
        $monthEnd = $carbonDate->copy()->endOfMonth()->toDateString();

        $payroll = $employees->map(function (Employee $emp) use ($monthStart, $monthEnd) {
            $monthRecords = EmployeeAttendance::where('employee_id', $emp->id)
                ->whereBetween('work_date', [$monthStart, $monthEnd])
                ->get();

            $lateMinutes = (int) $monthRecords->sum('late_minutes');
            $latePenalties = (int) $monthRecords->sum('late_penalty');
            $commissions = (int) $monthRecords->sum('commission_earned');
            $netSalary = max(0, (int) $emp->base_salary + $commissions - $latePenalties);

            return [
                'id' => $emp->id,
                'full_name' => $emp->full_name,
                'role_title' => $emp->role_title,
                'base_salary' => (int) $emp->base_salary,
                'commissions' => $commissions,
                'late_minutes' => $lateMinutes,
                'late_penalties' => $latePenalties,
                'net_salary' => $netSalary,
            ];
        });

        return Inertia::render('Tenant/Hr/Index', [
            'selectedDate' => $carbonDate->toDateString(),
            'isWorkDay' => $isWorkDay,
            'schedule' => [
                'work_start_time' => $workStart,
                'work_end_time' => $workEnd,
                'late_penalty_per_hour' => $penaltyPerHour,
            ],
            'attendances' => $attendances,
            'payroll' => $payroll,
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

        $dateStr = $attendance->work_date->toDateString();
        $arrivalTime = ! empty($validated['time'])
            ? Carbon::parse("{$dateStr} {$validated['time']}")
            : now();

        $expectedStart = Carbon::parse("{$dateStr} {$workStart}");
        $lateMinutes = $arrivalTime->greaterThan($expectedStart)
            ? (int) $expectedStart->diffInMinutes($arrivalTime)
            : 0;

        $latePenalty = $lateMinutes > 0
            ? (int) round(($lateMinutes / 60) * $penaltyPerHour)
            : 0;

        $attendance->update([
            'checked_in_at' => $arrivalTime,
            'late_minutes' => $lateMinutes,
            'late_penalty' => $latePenalty,
        ]);

        $msg = $lateMinutes > 0
            ? "Arrivée enregistrée avec {$lateMinutes} min de retard (retenue : {$latePenalty} FCFA)."
            : 'Arrivée enregistrée à l\'heure.';

        return back()->with('success', $msg);
    }

    public function checkOut(Request $request, EmployeeAttendance $attendance): RedirectResponse
    {
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

        $attendance->update(['checked_out_at' => $exitTime]);

        return back()->with('success', 'Heure de départ enregistrée.');
    }
}
