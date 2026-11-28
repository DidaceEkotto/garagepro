<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentLink;
use App\Models\B2bMessage;
use App\Models\MarketplaceOrder;
use App\Models\PlatformMessage;
use App\Models\RoadsideSosRequest;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

/**
 * Endpoint ultra-léger interrogé discrètement toutes les 10s par la sidebar professionnelle
 * pour rafraîchir les badges de notification sans bloquer ni recharger la navigation.
 */
class LiveBadgesController extends Controller
{
    public function __invoke(TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->getTenant();
        if (! $tenant) {
            return response()->json(['ok' => false], 403);
        }

        $tenantId = $tenant->id;

        // 1. Messagerie & Notifications non lues
        $unreadMessages = PlatformMessage::where('tenant_id', $tenantId)
            ->where('recipient_type', PlatformMessage::TYPE_TENANT)
            ->where('is_read', false)
            ->count();

        // 2. Rendez-vous en attente (RDV en ligne + liens soumis)
        $pendingAppointments = Appointment::where('tenant_id', $tenantId)
            ->where('status', Appointment::STATUS_PENDING)
            ->count();

        if (Schema::hasTable('appointment_links')) {
            $pendingAppointments += AppointmentLink::where('tenant_id', $tenantId)
                ->where('status', AppointmentLink::STATUS_SUBMITTED)
                ->count();
        }

        // 3. Alertes SOS Dépannage ouvertes à proximité + missions acceptées en cours
        $sosCount = 0;
        if (Schema::hasTable('roadside_sos_requests')) {
            $lat = $tenant->latitude;
            $lon = $tenant->longitude;
            $radius = max(10, (int) ($tenant->sos_radius_km ?: 35));

            $openSos = RoadsideSosRequest::where('status', RoadsideSosRequest::STATUS_OPEN)
                ->where('created_at', '>=', now()->subHours(24))
                ->get(['id', 'latitude', 'longitude']);

            foreach ($openSos as $r) {
                if ($lat === null || $lon === null) {
                    $sosCount++;
                } else {
                    $dist = RoadsideSosRequest::haversineKm((float) $lat, (float) $lon, (float) $r->latitude, (float) $r->longitude);
                    if ($dist <= $radius) {
                        $sosCount++;
                    }
                }
            }

            $sosCount += RoadsideSosRequest::where('accepted_tenant_id', $tenantId)
                ->whereIn('status', [RoadsideSosRequest::STATUS_ACCEPTED, RoadsideSosRequest::STATUS_ON_THE_WAY])
                ->count();
        }

        // 4. Commandes Marketplace en attente
        $pendingOrders = 0;
        if (Schema::hasTable('marketplace_orders')) {
            $pendingOrders = MarketplaceOrder::where('tenant_id', $tenantId)
                ->whereIn('status', ['pending', 'en_attente', 'paid'])
                ->count();
        }

        // 5. Messages Réseau Pro B2B non lus
        $unreadB2b = 0;
        if (Schema::hasTable('b2b_messages')) {
            $unreadB2b = B2bMessage::whereHas('conversation', function ($q) use ($tenantId) {
                $q->where('tenant_one_id', $tenantId)->orWhere('tenant_two_id', $tenantId);
            })
                ->where('sender_tenant_id', '!=', $tenantId)
                ->whereNull('read_at')
                ->count();
        }

        return response()->json([
            'ok' => true,
            'badges' => [
                '/messagerie' => $unreadMessages,
                '/rendez-vous' => $pendingAppointments,
                '/depannage-sos' => $sosCount,
                '/reseau-pro' => $unreadB2b,
                '/marketplace-gestion/commandes' => $pendingOrders,
            ],
        ]);
    }
}
