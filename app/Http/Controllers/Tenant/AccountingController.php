<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\Agency;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Services\SyscohadaSeederService;
use App\Services\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class AccountingController extends Controller
{
    public function index(
        Request $request,
        TenantContext $tenantContext,
        AccountingService $accounting,
        SyscohadaSeederService $seeder
    ): Response {
        if (! $request->user('web')->can('accounting.view')) {
            abort(403, 'Accès réservé à la comptabilité.');
        }

        $tenant = $tenantContext->getTenant();
        $seeder->provisionForTenant($tenant);

        $agencyId = $request->query('agency_id') ? (int) $request->query('agency_id') : null;

        return Inertia::render('Tenant/Accounting/Index', [
            'balance' => $accounting->generalBalance($tenant, $agencyId),
            'incomeStatement' => $accounting->incomeStatement($tenant, $agencyId),
            'vatSummary' => $accounting->vatSummary($tenant),
            'entries' => JournalEntry::with(['journal', 'lines.account'])->latest('id')->take(30)->get(),
            'fiscalYears' => FiscalYear::orderByDesc('label')->get(),
            'accounts' => AccountingAccount::orderBy('code')->get(['id', 'code', 'name']),
            'journals' => AccountingJournal::orderBy('code')->get(['id', 'code', 'name']),
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeEntry(Request $request, TenantContext $tenantContext, AccountingService $accounting): RedirectResponse
    {
        if (! $request->user('web')->can('accounting.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'journal_code' => ['required', 'string', 'max:10'],
            'entry_date' => ['required', 'date'],
            'label' => ['required', 'string', 'max:255'],
            'agency_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_code' => ['required', 'string', 'max:20'],
            'lines.*.debit' => ['required', 'integer', 'min:0'],
            'lines.*.credit' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $entry = $accounting->postEntry(
                tenant: $tenantContext->getTenant(),
                journalCode: $validated['journal_code'],
                label: $validated['label'],
                lines: $validated['lines'],
                agencyId: $validated['agency_id'] ?? null,
                entryDate: $validated['entry_date'],
                createdBy: $request->user('web')->id
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['entry' => $e->getMessage()]);
        }

        return back()->with('success', "Écriture comptable {$entry->entry_number} enregistrée.");
    }

    public function reverse(Request $request, JournalEntry $journalEntry, AccountingService $accounting): RedirectResponse
    {
        if (! $request->user('web')->can('accounting.manage')) {
            abort(403);
        }

        $accounting->reverseEntry($journalEntry, $request->user('web'));

        return back()->with('success', "Écriture {$journalEntry->entry_number} contre-passée.");
    }

    public function closeFiscalYear(Request $request, FiscalYear $fiscalYear, AccountingService $accounting): RedirectResponse
    {
        if (! $request->user('web')->can('accounting.manage')) {
            abort(403);
        }

        $accounting->closeFiscalYear($fiscalYear, $request->user('web'));

        return back()->with('success', "Exercice {$fiscalYear->label} clôturé.");
    }

    public function balancePdf(Request $request, TenantContext $tenantContext, AccountingService $accounting): HttpResponse
    {
        if (! $request->user('web')->can('accounting.view')) {
            abort(403);
        }

        $tenant = $tenantContext->getTenant();
        $balance = $accounting->generalBalance($tenant);

        $pdf = Pdf::loadView('pdf.balance', [
            'tenant' => $tenant,
            'balance' => $balance,
        ]);

        return $pdf->download("balance-syscohada-{$tenant->slug}.pdf");
    }
}
