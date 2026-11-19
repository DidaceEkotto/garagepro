<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AffiliateLink;
use App\Models\AffiliateReferral;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AffiliationController extends Controller
{
    public function index(TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();
        $link = AffiliateLink::where('tenant_id', $tenant->id)->first();

        $referrals = $link
            ? $link->referrals()
                ->with([
                    'referredTenant:id,name,email,phone,city,country,status,created_at',
                    'referredUser:id,name,email,phone',
                ])
                ->latest('id')
                ->get()
            : collect();

        return Inertia::render('Tenant/Affiliation/Index', [
            'affiliateLink' => $link ? [
                'id' => $link->id,
                'code' => $link->code,
                'share_url' => url('/inscription?ref='.$link->code),
                'is_blocked' => $link->is_blocked,
                'blocked_reason' => $link->blocked_reason,
                'clicks_count' => $link->clicks_count,
                'balance' => $link->balance,
                'total_earned' => $link->total_earned,
                'created_at' => $link->created_at,
            ] : null,
            'referrals' => $referrals,
            'monthlyPrice' => PlatformSetting::subscriptionPrice(),
            'commissionPerSignup' => PlatformSetting::affiliateCommission(),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->getTenant();
        $user = $request->user('web');

        if (AffiliateLink::where('tenant_id', $tenant->id)->exists()) {
            return back()->with('error', 'Vous disposez déjà d\'un lien d\'affiliation unique pour votre garage.');
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'min:4', 'max:24', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('affiliate_links', 'code')],
        ]);

        $code = ! empty($validated['code'])
            ? strtoupper(trim($validated['code']))
            : strtoupper(Str::substr(Str::slug($tenant->slug, ''), 0, 8) ?: 'GARAGE').'-'.strtoupper(Str::random(5));

        while (AffiliateLink::where('code', $code)->exists()) {
            $code = 'GP-'.strtoupper(Str::random(6));
        }

        $link = AffiliateLink::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'code' => $code,
            'is_blocked' => false,
            'clicks_count' => 0,
            'balance' => 0,
            'total_earned' => 0,
        ]);

        AuditLog::record(
            action: 'affiliate.link_created',
            subject: $link,
            actor: $user,
            tenantId: $tenant->id,
            metadata: ['code' => $code],
            request: $request
        );

        return back()->with('success', "Votre lien d'affiliation ({$code}) a été créé avec succès !");
    }

    public function paySubscription(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->getTenant();
        $user = $request->user('web');
        $basePrice = PlatformSetting::subscriptionPrice();
        $discount = min($basePrice, AffiliateReferral::availableDiscountForTenant($tenant));
        $price = max(0, $basePrice - $discount);

        $error = DB::transaction(function () use ($tenant, $user, $price, $request) {
            $link = AffiliateLink::where('tenant_id', $tenant->id)->lockForUpdate()->first();
            if (! $link) {
                return 'Vous devez d\'abord créer votre lien d\'affiliation.';
            }

            if ($link->is_blocked) {
                return 'Votre lien d\'affiliation est actuellement bloqué par l\'administration. Paiement impossible.';
            }

            if ($link->balance < $price) {
                return 'Votre solde d\'affiliation est insuffisant pour couvrir le montant de l\'abonnement.';
            }

            $link->decrement('balance', $price);

            $baseDate = ($tenant->subscription_ends_at && $tenant->subscription_ends_at->isFuture())
                ? $tenant->subscription_ends_at->copy()
                : (($tenant->status === Tenant::STATUS_TRIALING && $tenant->trial_ends_at && $tenant->trial_ends_at->isFuture())
                    ? $tenant->trial_ends_at->copy()
                    : now());

            $tenant->update([
                'status' => Tenant::STATUS_ACTIVE,
                'subscription_ends_at' => $baseDate->addDays(30),
            ]);

            AffiliateReferral::settleOnFirstSubscriptionPayment($tenant);

            $payment = Payment::create([
                'tenant_id' => $tenant->id,
                'payable_type' => Tenant::class,
                'payable_id' => $tenant->id,
                'reference_code' => 'AFF-'.strtoupper(Str::random(10)),
                'idempotency_key' => (string) Str::uuid(),
                'provider' => 'affiliate_balance',
                'payment_method' => 'affiliate_balance',
                'amount' => $price,
                'currency' => 'XAF',
                'status' => Payment::STATUS_SUCCESS,
                'paid_at' => now(),
                'metadata' => ['affiliate_link_id' => $link->id],
            ]);

            AuditLog::record(
                action: 'subscription.paid_with_affiliate',
                subject: $payment,
                actor: $user,
                tenantId: $tenant->id,
                metadata: ['amount' => $price, 'remaining_balance' => $link->fresh()->balance],
                request: $request
            );

            return null;
        });

        if ($error !== null) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Abonnement renouvelé avec succès grâce à votre solde d\'affiliation !');
    }
}
