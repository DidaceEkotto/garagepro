<?php

namespace App\Services;

use App\Mail\ClientReminderMail;
use App\Models\ClientReview;
use App\Models\RepairOrder;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Envoie automatiquement (zéro-cron) une enquête de satisfaction 1 à 5 étoiles
 * 24h après la clôture / livraison d'un Ordre de Réparation.
 */
class PostServiceReviewService
{
    public function __construct(
        private readonly GreenApiService $greenApi,
    ) {}

    public function dispatchDueForTenantThrottled(Tenant $tenant): int
    {
        $key = "post_service_reviews_ran:{$tenant->id}";
        if (! Cache::add($key, true, now()->addMinutes(60))) {
            return 0;
        }

        return $this->dispatchDueForTenant($tenant);
    }

    public function dispatchDueForTenant(Tenant $tenant, int $limit = 15): int
    {
        $existingRoIds = ClientReview::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->pluck('repair_order_id')
            ->all();

        $orders = RepairOrder::withoutGlobalScopes()
            ->with(['customer', 'vehicle'])
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [RepairOrder::STATUS_COMPLETED, RepairOrder::STATUS_DELIVERED])
            ->where('updated_at', '<=', now()->subHours(24))
            ->where('updated_at', '>=', now()->subDays(30))
            ->when(! empty($existingRoIds), fn ($q) => $q->whereNotIn('id', $existingRoIds))
            ->limit($limit)
            ->get();

        $sent = 0;

        foreach ($orders as $order) {
            $customer = $order->customer;
            if (! $customer) {
                continue;
            }

            $review = ClientReview::create([
                'tenant_id' => $tenant->id,
                'repair_order_id' => $order->id,
                'customer_id' => $customer->id,
                'token' => Str::random(48),
                'sent_at' => now(),
            ]);

            $reviewUrl = url("/avis/{$review->token}");
            $plate = $order->vehicle?->license_plate ? " ({$order->vehicle->license_plate})" : '';

            if (! empty($customer->phone)) {
                try {
                    $msg = "Bonjour {$customer->name},\n\n"
                        ."Merci pour votre confiance chez *{$tenant->name}* suite à l'intervention sur votre véhicule{$plate} (OR {$order->order_number}).\n\n"
                        ."Votre avis compte beaucoup ! Notez votre expérience en 10 secondes (1 à 5 étoiles) :\n{$reviewUrl}";
                    $this->greenApi->sendMessage($customer->phone, $msg);
                } catch (\Throwable $e) {
                    Log::warning('Post-service WhatsApp review invite failed', ['review_id' => $review->id, 'error' => $e->getMessage()]);
                }
            }

            if (! empty($customer->email)) {
                try {
                    Mail::to($customer->email)->send(new ClientReminderMail(
                        garageName: $tenant->name,
                        clientName: $customer->name,
                        subjectLine: "Votre avis suite à votre visite chez {$tenant->name}",
                        bodyText: "Nous espérons que votre véhicule{$plate} vous donne entière satisfaction.\n\nDonnez-nous une note rapide (1 à 5 étoiles) ici : {$reviewUrl}",
                    ));
                } catch (\Throwable $e) {
                    Log::warning('Post-service email review invite failed', ['review_id' => $review->id, 'error' => $e->getMessage()]);
                }
            }

            $sent++;
        }

        return $sent;
    }
}
