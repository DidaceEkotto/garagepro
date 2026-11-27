<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Mail\ClientReminderMail;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\RepairOrder;
use App\Services\AccountingService;
use App\Services\NumberSequenceService;
use App\Services\OverdueInvoiceReminderService;
use App\Services\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(TenantContext $tenantContext, OverdueInvoiceReminderService $overdueReminderService): Response
    {
        $this->authorize('viewAny', Invoice::class);

        if ($tenant = $tenantContext->getTenant()) {
            $overdueReminderService->dispatchDueForTenantThrottled($tenant);
        }

        $invoices = Invoice::with([
            'customer',
            'vehicle',
            'agency',
            'payments' => fn ($q) => $q->where('status', \App\Models\Payment::STATUS_SUCCESS)->latest('id'),
        ])
            ->latest('issued_at')
            ->paginate(20);

        $overdueCount = Invoice::whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIAL])
            ->where('type', Invoice::TYPE_INVOICE)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now()->startOfDay())
            ->count();

        return Inertia::render('Tenant/Invoices/Index', [
            'invoices' => $invoices,
            'overdueCount' => $overdueCount,
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'repairOrders' => RepairOrder::with(['customer', 'vehicle'])->whereNotIn('status', [RepairOrder::STATUS_DELIVERED])->orderByDesc('id')->get(),
        ]);
    }

    public function storeFromRepairOrder(
        Request $request,
        RepairOrder $repairOrder,
        TenantContext $tenantContext,
        NumberSequenceService $sequences,
        AccountingService $accounting
    ): RedirectResponse {
        $this->authorize('create', Invoice::class);

        $validated = $request->validate([
            'type' => ['nullable', Rule::in([Invoice::TYPE_INVOICE, Invoice::TYPE_PROFORMA])],
            'deposit_deducted' => ['nullable', 'integer', 'min:0'],
            'discount_amount' => ['nullable', 'integer', 'min:0'],
            'is_deferred_monthly' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', Rule::in(['main_oeuvre', 'piece', 'consommable', 'sous_traitance'])],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
        ]);

        $tenant = $tenantContext->getTenant();
        $type = $validated['type'] ?? Invoice::TYPE_INVOICE;

        $invoice = DB::transaction(function () use ($validated, $repairOrder, $tenant, $type, $sequences) {
            $subtotal = 0;
            foreach ($validated['items'] as $item) {
                $subtotal += (int) $item['quantity'] * (int) $item['unit_price'];
            }

            $discount = (int) ($validated['discount_amount'] ?? 0);
            $netHt = max(0, $subtotal - $discount);
            $vat = (int) round(($netHt * (float) $tenant->vat_rate) / 100);
            $totalTtc = $netHt + $vat;

            $invoice = Invoice::create([
                'tenant_id' => $tenant->id,
                'agency_id' => $repairOrder->agency_id,
                'customer_id' => $repairOrder->customer_id,
                'vehicle_id' => $repairOrder->vehicle_id,
                'repair_order_id' => $repairOrder->id,
                'invoice_number' => $sequences->nextInvoiceNumber($tenant->id, $type),
                'type' => $type,
                'status' => Invoice::STATUS_ISSUED,
                'is_deferred_monthly' => (bool) ($validated['is_deferred_monthly'] ?? false),
                'vat_rate' => $tenant->vat_rate,
                'subtotal_ht' => $subtotal,
                'discount_amount' => $discount,
                'vat_amount' => $vat,
                'deposit_deducted' => (int) ($validated['deposit_deducted'] ?? 0),
                'total_ttc' => $totalTtc,
                'paid_amount' => 0,
                'issued_at' => now(),
                'due_at' => now()->addDays(30),
            ]);

            foreach ($validated['items'] as $item) {
                InvoiceItem::create([
                    'tenant_id' => $tenant->id,
                    'invoice_id' => $invoice->id,
                    'type' => $item['type'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => (int) $item['quantity'] * (int) $item['unit_price'],
                ]);
            }

            return $invoice;
        });

        if ($invoice->type === Invoice::TYPE_INVOICE && $invoice->total_ttc > 0) {
            $accounting->recordInvoiceEntry($invoice);
        }

        AuditLog::record('invoice.created', subject: $invoice, actor: $request->user('web'), tenantId: $tenant->id, request: $request);

        return back()->with('success', "Facture {$invoice->invoice_number} générée.");
    }

    public function createCreditNote(
        Request $request,
        Invoice $invoice,
        TenantContext $tenantContext,
        NumberSequenceService $sequences
    ): RedirectResponse {
        $this->authorize('create', Invoice::class);

        $tenant = $tenantContext->getTenant();

        $creditNote = DB::transaction(function () use ($invoice, $tenant, $sequences) {
            return Invoice::create([
                'tenant_id' => $tenant->id,
                'agency_id' => $invoice->agency_id,
                'customer_id' => $invoice->customer_id,
                'vehicle_id' => $invoice->vehicle_id,
                'repair_order_id' => $invoice->repair_order_id,
                'parent_invoice_id' => $invoice->id,
                'invoice_number' => $sequences->nextInvoiceNumber($tenant->id, Invoice::TYPE_CREDIT_NOTE),
                'type' => Invoice::TYPE_CREDIT_NOTE,
                'status' => Invoice::STATUS_PAID,
                'vat_rate' => $invoice->vat_rate,
                'subtotal_ht' => $invoice->subtotal_ht,
                'discount_amount' => $invoice->discount_amount,
                'vat_amount' => $invoice->vat_amount,
                'deposit_deducted' => 0,
                'total_ttc' => $invoice->total_ttc,
                'paid_amount' => $invoice->total_ttc,
                'issued_at' => now(),
                'notes' => "Avoir sur facture {$invoice->invoice_number}",
            ]);
        });

        AuditLog::record('invoice.credit_note_created', subject: $creditNote, actor: $request->user('web'), tenantId: $tenant->id, request: $request);

        return back()->with('success', "Avoir {$creditNote->invoice_number} émis.");
    }

    public function pdf(Invoice $invoice, TenantContext $tenantContext): HttpResponse
    {
        $this->authorize('view', $invoice);

        $invoice->load(['customer', 'vehicle', 'agency', 'items']);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'tenant' => $tenantContext->getTenant(),
        ]);

        return $pdf->download("{$invoice->invoice_number}.pdf");
    }

    public function remind(Request $request, Invoice $invoice, TenantContext $tenantContext): RedirectResponse
    {
        $this->authorize('view', $invoice);
        $invoice->load('customer');

        $customer = $invoice->customer;
        if (! $customer || empty($customer->email)) {
            return back()->with('error', "Ce client n'a pas d'adresse e-mail enregistrée.");
        }

        $remaining = max(0, (int) $invoice->total_ttc - (int) $invoice->deposit_deducted - (int) $invoice->paid_amount);
        $formattedRemaining = number_format($remaining, 0, ',', ' ').' FCFA';
        $tenant = $tenantContext->getTenant();

        Mail::to($customer->email)->send(new ClientReminderMail(
            garageName: $tenant?->name ?? config('app.name', 'MboaCar'),
            clientName: $customer->name,
            subjectLine: "Relance facture {$invoice->invoice_number}",
            bodyText: "Nous vous rappelons que la facture {$invoice->invoice_number} reste en attente de règlement pour un solde restant de {$formattedRemaining}.\n\nMerci de bien vouloir procéder au règlement ou de nous contacter.",
        ));

        AuditLog::record('invoice.reminded', subject: $invoice, actor: $request->user('web'), tenantId: $tenantContext->id(), request: $request);

        return back()->with('success', "Relance envoyée à {$customer->name} ({$customer->email}) pour la facture {$invoice->invoice_number}.");
    }

    public function runOverdueReminders(
        Request $request,
        TenantContext $tenantContext,
        OverdueInvoiceReminderService $overdueReminderService
    ): RedirectResponse {
        $this->authorize('viewAny', Invoice::class);
        $tenant = $tenantContext->getTenant();
        $sent = $tenant ? $overdueReminderService->dispatchDueForTenant($tenant, 30) : 0;

        return back()->with(
            $sent > 0 ? 'success' : 'info',
            $sent > 0
                ? "{$sent} relance(s) automatique(s) d'impayés (J+3 / J+7 / J+15) envoyée(s) par WhatsApp/E-mail."
                : 'Aucune nouvelle facture échue à relancer pour le moment (les paliers J+3, J+7 et J+15 sont à jour).'
        );
    }

    public function saveInstallmentPlan(Request $request, Invoice $invoice, TenantContext $tenantContext): RedirectResponse
    {
        $this->authorize('view', $invoice);

        $validated = $request->validate([
            'installments' => ['required', 'array', 'min:1', 'max:12'],
            'installments.*.label' => ['required', 'string', 'max:80'],
            'installments.*.amount' => ['required', 'integer', 'min:100'],
            'installments.*.due_date' => ['required', 'date'],
            'installments.*.paid' => ['nullable', 'boolean'],
        ]);

        $plan = collect($validated['installments'])->map(fn (array $row) => [
            'label' => $row['label'],
            'amount' => (int) $row['amount'],
            'due_date' => $row['due_date'],
            'paid' => (bool) ($row['paid'] ?? false),
        ])->values()->all();

        $invoice->update(['installment_plan' => $plan]);

        AuditLog::record('invoice.installment_plan_updated', subject: $invoice, actor: $request->user('web'), tenantId: $tenantContext->id(), request: $request);

        return back()->with('success', "Échéancier enregistré pour la facture {$invoice->invoice_number}.");
    }
}
