<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moteur comptable SYSCOHADA révisé :
 * - Écritures équilibrées (débit = crédit) vérifiées avant persistance
 * - Immuabilité des écritures validées (contre-passation uniquement)
 * - Génération automatique depuis factures et paiements
 * - États comptables et clôture d'exercice
 */
class AccountingService
{
    public function __construct(private readonly SyscohadaSeederService $seeder) {}

    /**
     * Enregistre une pièce comptable équilibrée dans un exercice ouvert.
     *
     * @param  array<int, array{account_code: string, debit?: int, credit?: int, auxiliary_label?: ?string}>  $lines
     */
    public function postEntry(
        Tenant $tenant,
        string $journalCode,
        string $label,
        array $lines,
        ?int $agencyId = null,
        ?string $entryDate = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $createdBy = null,
        ?int $reversedEntryId = null
    ): JournalEntry {
        $this->seeder->provisionForTenant($tenant);

        $date = $entryDate ?? now()->toDateString();

        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($lines as $line) {
            $debit = (int) ($line['debit'] ?? 0);
            $credit = (int) ($line['credit'] ?? 0);
            if ($debit < 0 || $credit < 0 || ($debit === 0 && $credit === 0)) {
                throw new InvalidArgumentException('Chaque ligne comptable doit avoir un montant positif au débit ou au crédit.');
            }
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        if ($totalDebit === 0 || $totalDebit !== $totalCredit) {
            throw new InvalidArgumentException("Écriture déséquilibrée : débit ({$totalDebit}) ≠ crédit ({$totalCredit}).");
        }

        return DB::transaction(function () use (
            $tenant,
            $journalCode,
            $label,
            $lines,
            $agencyId,
            $date,
            $sourceType,
            $sourceId,
            $createdBy,
            $reversedEntryId
        ): JournalEntry {
            $fiscalYear = FiscalYear::withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->where('start_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->first();

            if (! $fiscalYear) {
                throw new InvalidArgumentException("Aucun exercice comptable défini pour la date {$date}.");
            }

            if ($fiscalYear->isClosed()) {
                throw new InvalidArgumentException("L'exercice {$fiscalYear->label} est clôturé et verrouillé.");
            }

            $journal = AccountingJournal::withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->where('code', $journalCode)
                ->firstOrFail();

            $prefix = "{$journal->code}-".substr($date, 0, 4).'-';
            $lastNumber = JournalEntry::withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->where('entry_number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('entry_number');

            $next = $lastNumber ? ((int) substr($lastNumber, strlen($prefix))) + 1 : 1;
            $entryNumber = $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);

            $entry = JournalEntry::create([
                'tenant_id' => $tenant->id,
                'agency_id' => $agencyId,
                'fiscal_year_id' => $fiscalYear->id,
                'journal_id' => $journal->id,
                'entry_number' => $entryNumber,
                'entry_date' => $date,
                'label' => $label,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reversed_entry_id' => $reversedEntryId,
                'status' => JournalEntry::STATUS_VALIDATED,
                'created_by' => $createdBy,
            ]);

            foreach ($lines as $line) {
                $account = AccountingAccount::withoutTenantScope()
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $line['account_code'])
                    ->firstOrFail();

                JournalEntryLine::create([
                    'tenant_id' => $tenant->id,
                    'agency_id' => $agencyId,
                    'journal_entry_id' => $entry->id,
                    'account_id' => $account->id,
                    'auxiliary_label' => $line['auxiliary_label'] ?? null,
                    'debit' => (int) ($line['debit'] ?? 0),
                    'credit' => (int) ($line['credit'] ?? 0),
                ]);
            }

            return $entry->load('lines.account');
        });
    }

    /**
     * Contre-passe une écriture validée (seule méthode autorisée pour corriger une écriture).
     */
    public function reverseEntry(JournalEntry $entry, User $actor): JournalEntry
    {
        if ($entry->status === JournalEntry::STATUS_REVERSED) {
            throw new InvalidArgumentException('Cette écriture a déjà été contre-passée.');
        }

        $entry->load('lines.account', 'journal');
        $tenant = Tenant::findOrFail($entry->tenant_id);

        $reversedLines = $entry->lines->map(fn (JournalEntryLine $line) => [
            'account_code' => $line->account->code,
            'debit' => $line->credit,
            'credit' => $line->debit,
            'auxiliary_label' => $line->auxiliary_label,
        ])->all();

        return DB::transaction(function () use ($entry, $tenant, $reversedLines, $actor): JournalEntry {
            $reversal = $this->postEntry(
                tenant: $tenant,
                journalCode: $entry->journal->code,
                label: "Contre-passation {$entry->entry_number} — {$entry->label}",
                lines: $reversedLines,
                agencyId: $entry->agency_id,
                sourceType: $entry->source_type,
                sourceId: $entry->source_id,
                createdBy: $actor->id,
                reversedEntryId: $entry->id
            );

            $entry->update(['status' => JournalEntry::STATUS_REVERSED]);

            return $reversal;
        });
    }

    /**
     * Génère l'écriture automatique de vente pour une facture client.
     */
    public function recordInvoiceEntry(Invoice $invoice): JournalEntry
    {
        $tenant = Tenant::findOrFail($invoice->tenant_id);
        $invoice->load('items', 'customer');

        $partsHt = (int) $invoice->items->where('type', 'piece')->sum('total_price');
        $servicesHt = max(0, (int) $invoice->subtotal_ht - (int) $invoice->discount_amount - $partsHt);
        $vat = (int) $invoice->vat_amount;
        $totalTtc = (int) $invoice->total_ttc;

        $lines = [
            [
                'account_code' => '411',
                'debit' => $totalTtc,
                'credit' => 0,
                'auxiliary_label' => $invoice->customer?->name,
            ],
        ];

        if ($servicesHt > 0) {
            $lines[] = ['account_code' => '706', 'debit' => 0, 'credit' => $servicesHt];
        }
        if ($partsHt > 0) {
            $lines[] = ['account_code' => '701', 'debit' => 0, 'credit' => $partsHt];
        }
        if ($vat > 0) {
            $lines[] = ['account_code' => '4431', 'debit' => 0, 'credit' => $vat];
        }

        return $this->postEntry(
            tenant: $tenant,
            journalCode: 'VE',
            label: "Facture {$invoice->invoice_number}",
            lines: $lines,
            agencyId: $invoice->agency_id,
            entryDate: $invoice->issued_at?->toDateString() ?? now()->toDateString(),
            sourceType: Invoice::class,
            sourceId: $invoice->id
        );
    }

    /**
     * Génère l'écriture d'encaissement client (Caisse, Mobile Money / SaSPay, ou Banque).
     */
    public function recordPaymentEntry(Payment $payment): JournalEntry
    {
        $tenant = Tenant::findOrFail($payment->tenant_id);

        [$journalCode, $treasuryAccount] = match ($payment->payment_method) {
            'especes' => ['CA', '571'],
            'virement', 'cheque', 'carte' => ['BQ', '521'],
            default => ['MM', '522'], // mtn_momo, orange_money, saspay_checkout, saspay_softpay
        };

        return $this->postEntry(
            tenant: $tenant,
            journalCode: $journalCode,
            label: "Encaissement {$payment->reference_code}",
            lines: [
                ['account_code' => $treasuryAccount, 'debit' => $payment->amount, 'credit' => 0],
                ['account_code' => '411', 'debit' => 0, 'credit' => $payment->amount],
            ],
            agencyId: $payment->agency_id,
            entryDate: $payment->paid_at?->toDateString() ?? now()->toDateString(),
            sourceType: Payment::class,
            sourceId: $payment->id
        );
    }

    public function generalBalance(Tenant $tenant, ?int $agencyId = null): Collection
    {
        return JournalEntryLine::withoutTenantScope()
            ->where('journal_entry_lines.tenant_id', $tenant->id)
            ->when($agencyId !== null, fn ($q) => $q->where('journal_entry_lines.agency_id', $agencyId))
            ->join('accounting_accounts', 'accounting_accounts.id', '=', 'journal_entry_lines.account_id')
            ->groupBy('accounting_accounts.id', 'accounting_accounts.code', 'accounting_accounts.name', 'accounting_accounts.account_class')
            ->selectRaw('accounting_accounts.code, accounting_accounts.name, accounting_accounts.account_class, SUM(journal_entry_lines.debit) as total_debit, SUM(journal_entry_lines.credit) as total_credit')
            ->orderBy('accounting_accounts.code')
            ->get();
    }

    public function incomeStatement(Tenant $tenant, ?int $agencyId = null): array
    {
        $balance = $this->generalBalance($tenant, $agencyId);

        $revenues = $balance->where('account_class', 7)->sum(fn ($r) => (int) $r->total_credit - (int) $r->total_debit);
        $expenses = $balance->where('account_class', 6)->sum(fn ($r) => (int) $r->total_debit - (int) $r->total_credit);

        return [
            'revenues' => (int) $revenues,
            'expenses' => (int) $expenses,
            'net_result' => (int) ($revenues - $expenses),
        ];
    }

    public function vatSummary(Tenant $tenant): array
    {
        $balance = $this->generalBalance($tenant)->keyBy('code');

        $collected = isset($balance['4431']) ? ((int) $balance['4431']->total_credit - (int) $balance['4431']->total_debit) : 0;
        $deductible = isset($balance['4452']) ? ((int) $balance['4452']->total_debit - (int) $balance['4452']->total_credit) : 0;

        return [
            'vat_collected' => $collected,
            'vat_deductible' => $deductible,
            'vat_due' => max(0, $collected - $deductible),
        ];
    }

    public function closeFiscalYear(FiscalYear $fiscalYear, User $actor): FiscalYear
    {
        if ($fiscalYear->isClosed()) {
            throw new InvalidArgumentException('Cet exercice est déjà clôturé.');
        }

        $totals = JournalEntryLine::withoutTenantScope()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.fiscal_year_id', $fiscalYear->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as sum_debit, COALESCE(SUM(credit), 0) as sum_credit')
            ->first();

        if ((int) $totals->sum_debit !== (int) $totals->sum_credit) {
            throw new InvalidArgumentException('Clôture impossible : la balance de l\'exercice est déséquilibrée.');
        }

        $fiscalYear->update([
            'status' => FiscalYear::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => $actor->id,
        ]);

        return $fiscalYear->fresh();
    }
}
