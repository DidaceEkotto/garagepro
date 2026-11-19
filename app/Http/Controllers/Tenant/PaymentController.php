<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AffiliateLink;
use App\Models\AffiliateReferral;
use App\Models\CashRegisterClosure;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Services\PaymentService;
use App\Services\SaspayClient;
use App\Services\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class PaymentController extends Controller
{
    public function subscriptionPage(TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();
        $payments = Payment::where('payable_type', get_class($tenant))
            ->where('payable_id', $tenant->id)
            ->latest()
            ->take(50)
            ->get();

        $affiliateLink = AffiliateLink::where('tenant_id', $tenant->id)->first();
        $monthlyPrice = PlatformSetting::subscriptionPrice();
        $referralDiscount = min($monthlyPrice, AffiliateReferral::availableDiscountForTenant($tenant));

        return Inertia::render('Tenant/Subscription/Index', [
            'monthlyPrice' => $monthlyPrice,
            'referralDiscount' => $referralDiscount,
            'effectivePrice' => max(0, $monthlyPrice - $referralDiscount),
            'gracePeriodDays' => PlatformSetting::gracePeriodDays(),
            'payments' => $payments,
            'affiliateLink' => $affiliateLink ? [
                'code' => $affiliateLink->code,
                'balance' => $affiliateLink->balance,
                'is_blocked' => $affiliateLink->is_blocked,
                'blocked_reason' => $affiliateLink->blocked_reason,
            ] : null,
        ]);
    }

    public function exportSubscriptionsPdf(TenantContext $tenantContext): HttpResponse
    {
        $tenant = $tenantContext->getTenant();
        $payments = Payment::where('payable_type', get_class($tenant))
            ->where('payable_id', $tenant->id)
            ->latest()
            ->get();

        $pdf = Pdf::loadView('pdf.subscriptions-history', [
            'tenant' => $tenant,
            'payments' => $payments,
        ]);

        return $pdf->download("historique-abonnements-{$tenant->slug}.pdf");
    }

    public function exportSubscriptionsExcel(TenantContext $tenantContext)
    {
        $tenant = $tenantContext->getTenant();
        $payments = Payment::where('payable_type', get_class($tenant))
            ->where('payable_id', $tenant->id)
            ->latest()
            ->get();

        $filename = "historique-abonnements-{$tenant->slug}.csv";

        return response()->streamDownload(function () use ($payments) {
            $handle = fopen('php://output', 'w');
            // UTF-8 BOM pour Excel
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Référence', 'Méthode / Fournisseur', 'Montant', 'Devise', 'Statut', 'Date paiement', 'Date création'], ';');

            foreach ($payments as $p) {
                fputcsv($handle, [
                    $p->reference_code,
                    $p->payment_method ?: $p->provider,
                    $p->amount,
                    $p->currency,
                    $p->status,
                    $p->paid_at ? $p->paid_at->format('d/m/Y H:i') : '-',
                    $p->created_at ? $p->created_at->format('d/m/Y H:i') : '-',
                ], ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function paySubscription(
        Request $request,
        TenantContext $tenantContext,
        PaymentService $paymentService
    ): BaseResponse {
        $tenant = $tenantContext->getTenant();
        $user = $request->user('web');
        $price = PlatformSetting::subscriptionPrice();
        $discount = min($price, AffiliateReferral::availableDiscountForTenant($tenant));
        $finalAmount = max(0, $price - $discount);

        $returnUrl = url('/paiements/__PAYMENT_ID__/retour');

        $payment = $paymentService->initiateSaspayCheckout(
            tenant: $tenant,
            payable: $tenant,
            amount: $finalAmount,
            description: "Abonnement mensuel GaragePro — {$tenant->name}",
            customerEmail: $user->email,
            customerName: $tenant->name,
            returnUrl: $returnUrl,
        );

        if ($payment->status === Payment::STATUS_FAILED || empty($payment->checkout_url)) {
            return back()->with('error', "Service de paiement momentanément indisponible, réessayez dans quelques minutes. (Réf : {$payment->reference_code})");
        }

        return Inertia::location($payment->checkout_url);
    }

    public function payInvoice(
        Request $request,
        Invoice $invoice,
        TenantContext $tenantContext,
        PaymentService $paymentService
    ): BaseResponse {
        if (! $request->user('web')->can('payments.manage')) {
            abort(403, 'Permission requise pour encaisser un paiement.');
        }

        $validated = $request->validate([
            'payment_method' => ['required', Rule::in(['saspay_checkout', 'saspay_softpay', 'especes', 'mtn_momo', 'orange_money', 'virement', 'cheque', 'carte'])],
            'amount' => ['required', 'integer', 'min:100'],
            'country' => ['nullable', 'string', 'max:5'],
            'network' => ['nullable', 'string', 'max:30'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
        ]);

        $balanceDue = $invoice->balanceDue();
        $amount = min((int) $validated['amount'], $balanceDue);
        if ($amount <= 0) {
            return back()->with('info', 'Ce paiement a déjà été traité, aucun double prélèvement n\'a eu lieu.');
        }

        $tenant = $tenantContext->getTenant();

        if ($validated['payment_method'] === 'saspay_checkout') {
            $invoice->load('customer');
            $payment = $paymentService->initiateSaspayCheckout(
                tenant: $tenant,
                payable: $invoice,
                amount: $amount,
                description: "Règlement facture {$invoice->invoice_number}",
                customerEmail: $invoice->customer->email ?? $tenant->email,
                customerName: $invoice->customer->name,
                returnUrl: url('/paiements/__PAYMENT_ID__/retour'),
                agencyId: $invoice->agency_id,
            );

            if ($payment->status === Payment::STATUS_FAILED || empty($payment->checkout_url)) {
                return back()->with('error', "Service de paiement momentanément indisponible. (Réf : {$payment->reference_code})");
            }

            return Inertia::location($payment->checkout_url);
        }

        if ($validated['payment_method'] === 'saspay_softpay') {
            $invoice->load('customer');
            $payment = $paymentService->initiateSaspaySoftpay(
                tenant: $tenant,
                payable: $invoice,
                amount: $amount,
                country: $validated['country'] ?? 'CM',
                network: $validated['network'] ?? 'MTN',
                customer: [
                    'name' => $invoice->customer->name,
                    'email' => $invoice->customer->email ?? $tenant->email,
                    'phone' => $validated['customer_phone'] ?? $invoice->customer->phone,
                ],
                agencyId: $invoice->agency_id,
            );

            if (! empty($payment->checkout_url)) {
                return Inertia::location($payment->checkout_url);
            }

            return redirect()->route('tenant.payments.return', $payment);
        }

        $payment = $paymentService->recordOfflinePayment($invoice, $amount, $validated['payment_method'], $request->user('web'));

        return back()->with('success', "Paiement enregistré (Réf : {$payment->reference_code}).");
    }

    /**
     * Retour client (return_url) : interroge toujours le statut réel côté serveur via /verify/.
     */
    public function returnCallback(
        Payment $payment,
        SaspayClient $saspayClient,
        PaymentService $paymentService,
        TenantContext $tenantContext
    ): Response {
        if ($payment->status === Payment::STATUS_PENDING && ! empty($payment->provider_payment_id)) {
            try {
                $data = $saspayClient->verifyPayment($payment->provider_payment_id);
                $remoteStatus = strtoupper((string) ($data['status'] ?? 'PENDING'));

                if ($remoteStatus === 'SUCCESS') {
                    $payment = $paymentService->markPaymentSucceeded($payment, $data);
                } elseif (in_array($remoteStatus, ['FAILED', 'CANCELLED'], true)) {
                    $payment->update([
                        'status' => strtolower($remoteStatus),
                        'failure_reason' => 'Le paiement a échoué : votre compte n\'a pas été débité, vous pouvez réessayer.',
                    ]);
                    $payment->refresh();
                }
            } catch (\Throwable) {
                // On conserve pending si l'API est temporairement injoignable.
            }
        }

        $tenant = $tenantContext->getTenant()->fresh();
        $message = match ($payment->status) {
            Payment::STATUS_SUCCESS => $payment->payable_type === get_class($tenant)
                ? 'Paiement confirmé, votre abonnement est actif jusqu\'au '.$tenant->subscription_ends_at?->format('d/m/Y').'.'
                : 'Paiement confirmé, merci pour votre règlement.',
            Payment::STATUS_FAILED, Payment::STATUS_CANCELLED => 'Le paiement a échoué : votre compte n\'a pas été débité, vous pouvez réessayer.',
            default => 'Paiement en attente de confirmation sur votre téléphone.',
        };

        return Inertia::render('Tenant/Payments/Return', [
            'payment' => [
                'id' => $payment->id,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'reference_code' => $payment->reference_code,
            ],
            'message' => $message,
        ]);
    }

    public function receiptPdf(Payment $payment, TenantContext $tenantContext): HttpResponse
    {
        $pdf = Pdf::loadView('pdf.receipt', [
            'payment' => $payment->load('agency'),
            'tenant' => $tenantContext->getTenant(),
        ]);

        return $pdf->download("recu-{$payment->reference_code}.pdf");
    }

    public function printReceipt(Payment $payment, TenantContext $tenantContext): \Illuminate\Contracts\View\View
    {
        return view('pdf.receipt_print', [
            'payment' => $payment->load('agency'),
            'tenant' => $tenantContext->getTenant(),
        ]);
    }

    public function closeCashRegister(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        if (! $request->user('web')->can('cash_register.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantContext->id())],
            'closure_date' => ['required', 'date'],
            'cash_total' => ['required', 'integer', 'min:0'],
            'mobile_money_total' => ['required', 'integer', 'min:0'],
            'card_bank_total' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $total = (int) $validated['cash_total'] + (int) $validated['mobile_money_total'] + (int) $validated['card_bank_total'];

        CashRegisterClosure::updateOrCreate(
            [
                'tenant_id' => $tenantContext->id(),
                'agency_id' => $validated['agency_id'],
                'closure_date' => $validated['closure_date'],
            ],
            [
                'closed_by' => $request->user('web')->id,
                'cash_total' => $validated['cash_total'],
                'mobile_money_total' => $validated['mobile_money_total'],
                'card_bank_total' => $validated['card_bank_total'],
                'total_amount' => $total,
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return back()->with('success', 'Clôture de caisse journalière enregistrée.');
    }
}
