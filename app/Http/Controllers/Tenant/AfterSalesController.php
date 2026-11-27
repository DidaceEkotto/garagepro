<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AfterSalesCase;
use App\Models\Agency;
use App\Models\ClientReview;
use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\Vehicle;
use App\Services\PostServiceReviewService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AfterSalesController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, PostServiceReviewService $reviewService): Response
    {
        if (! $request->user('web')->can('after_sales.manage')) {
            abort(403);
        }

        if ($tenant = $tenantContext->getTenant()) {
            $reviewService->dispatchDueForTenantThrottled($tenant);
        }

        $reviews = ClientReview::with(['customer:id,name,phone', 'repairOrder:id,order_number'])
            ->latest('id')
            ->take(25)
            ->get();

        $avgRating = round((float) (ClientReview::whereNotNull('rating')->avg('rating') ?? 0), 1);

        return Inertia::render('Tenant/AfterSales/Index', [
            'cases' => AfterSalesCase::with('customer')->latest()->paginate(20),
            'loyaltyCards' => LoyaltyCard::with('customer')->latest()->take(20)->get(),
            'clientReviews' => $reviews,
            'averageRating' => $avgRating,
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'vehicles' => Vehicle::orderBy('license_plate')->get(['id', 'customer_id', 'license_plate', 'brand', 'model']),
        ]);
    }

    public function dispatchReviews(Request $request, TenantContext $tenantContext, PostServiceReviewService $reviewService): RedirectResponse
    {
        if (! $request->user('web')->can('after_sales.manage')) {
            abort(403);
        }

        $tenant = $tenantContext->getTenant();
        $sent = $tenant ? $reviewService->dispatchDueForTenant($tenant, 30) : 0;

        return back()->with(
            $sent > 0 ? 'success' : 'info',
            $sent > 0
                ? "{$sent} lien(s) d'avis client (1 à 5 étoiles) envoyé(s) sur WhatsApp/E-mail."
                : 'Aucun ordre de réparation clôturé depuis plus de 24h en attente d\'enquête satisfaction.'
        );
    }

    public function storeCase(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        if (! $request->user('web')->can('after_sales.manage')) {
            abort(403);
        }

        $tenantId = $tenantContext->id();
        $validated = $request->validate([
            'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId)],
            'type' => ['required', Rule::in(['reclamation', 'garantie', 'satisfaction'])],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        AfterSalesCase::create([
            ...$validated,
            'tenant_id' => $tenantId,
            'status' => 'ouvert',
        ]);

        return back()->with('success', 'Dossier après-vente enregistré.');
    }

    public function awardLoyaltyPoints(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        if (! $request->user('web')->can('after_sales.manage')) {
            abort(403);
        }

        $tenantId = $tenantContext->id();
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'points' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $card = LoyaltyCard::firstOrCreate(
            ['tenant_id' => $tenantId, 'customer_id' => $validated['customer_id']],
            ['card_number' => 'FID-'.strtoupper(Str::random(6)), 'points' => 0, 'tier' => 'standard']
        );

        $card->increment('points', $validated['points']);
        $card->refresh();

        $tier = $card->points >= 1000 ? 'or' : ($card->points >= 300 ? 'argent' : 'standard');
        if ($card->tier !== $tier) {
            $card->update(['tier' => $tier]);
        }

        return back()->with('success', "Points de fidélité crédités ({$card->points} pts).");
    }
}
