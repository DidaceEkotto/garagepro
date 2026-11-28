<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\MarketplaceOrderMessage;
use App\Models\PlatformMessage;
use App\Services\GreenApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page publique de suivi de commande Marketplace (/suivi-commande/{token})
 * avec itinéraire OpenStreetMap et chat en direct Client ↔ Vendeur (sans compte client requis).
 */
class PublicOrderTrackingController extends Controller
{
    public function show(string $token): Response
    {
        $order = MarketplaceOrder::with([
            'items.tenant:id,name,phone,city,formatted_location,latitude,longitude',
            'items.messages',
        ])->where('tracking_token', $token)->firstOrFail();

        return Inertia::render('Public/OrderTrack', [
            'order' => [
                'order_number' => $order->order_number,
                'tracking_token' => $order->tracking_token,
                'buyer_name' => $order->buyer_name,
                'delivery_address' => $order->delivery_address,
                'delivery_latitude' => $order->delivery_latitude ? (float) $order->delivery_latitude : null,
                'delivery_longitude' => $order->delivery_longitude ? (float) $order->delivery_longitude : null,
                'total_amount' => $order->total_amount,
                'created_at' => $order->created_at?->format('d/m/Y à H:i'),
                'items' => $order->items->map(fn (MarketplaceOrderItem $item) => [
                    'id' => $item->id,
                    'product_title' => $item->product_title,
                    'quantity' => $item->quantity,
                    'subtotal' => $item->subtotal,
                    'seller_status' => $item->seller_status,
                    'seller' => [
                        'name' => $item->tenant?->name,
                        'phone' => $item->tenant?->phone,
                        'formatted_location' => $item->tenant?->formatted_location ?: $item->tenant?->city,
                        'latitude' => $item->tenant?->latitude ? (float) $item->tenant->latitude : null,
                        'longitude' => $item->tenant?->longitude ? (float) $item->tenant->longitude : null,
                    ],
                    'messages' => $item->messages->map(fn (MarketplaceOrderMessage $m) => [
                        'id' => $m->id,
                        'sender_type' => $m->sender_type,
                        'sender_name' => $m->sender_name,
                        'body' => $m->body,
                        'created_at' => $m->created_at?->format('d/m H:i'),
                    ])->values(),
                ])->values(),
            ],
        ]);
    }

    public function messages(string $token, MarketplaceOrderItem $orderItem): JsonResponse
    {
        $order = MarketplaceOrder::where('tracking_token', $token)->firstOrFail();
        if ((int) $orderItem->marketplace_order_id !== (int) $order->id) {
            abort(404);
        }

        $messages = $orderItem->messages()
            ->get()
            ->map(fn (MarketplaceOrderMessage $m) => [
                'id' => $m->id,
                'sender_type' => $m->sender_type,
                'sender_name' => $m->sender_name,
                'body' => $m->body,
                'created_at' => $m->created_at?->format('d/m H:i'),
            ])
            ->values();

        return response()->json([
            'ok' => true,
            'seller_status' => $orderItem->seller_status,
            'messages' => $messages,
        ]);
    }

    public function sendMessage(
        Request $request,
        string $token,
        MarketplaceOrderItem $orderItem,
        GreenApiService $greenApi
    ): JsonResponse {
        $order = MarketplaceOrder::where('tracking_token', $token)->firstOrFail();
        if ((int) $orderItem->marketplace_order_id !== (int) $order->id) {
            abort(404);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:1500'],
        ]);

        $message = MarketplaceOrderMessage::create([
            'marketplace_order_item_id' => $orderItem->id,
            'sender_type' => MarketplaceOrderMessage::SENDER_BUYER,
            'sender_name' => $order->buyer_name,
            'body' => trim($validated['body']),
            'is_read' => false,
        ]);

        // Notifie l'espace admin du vendeur + WhatsApp synchrone
        $orderItem->load('tenant');
        $seller = $orderItem->tenant;
        if ($seller) {
            try {
                PlatformMessage::create([
                    'tenant_id' => $seller->id,
                    'sender_type' => PlatformMessage::TYPE_CUSTOMER,
                    'sender_name' => $order->buyer_name,
                    'recipient_type' => PlatformMessage::TYPE_TENANT,
                    'recipient_id' => $seller->id,
                    'subject' => "Message client commande {$order->order_number}",
                    'body' => trim($validated['body']),
                    'is_read' => false,
                ]);
                Cache::forget("mboacar_tenant_nav_{$seller->id}");
            } catch (\Throwable) {
                // non-blocking
            }

            if (! empty($seller->phone)) {
                try {
                    $greenApi->sendMessage(
                        $seller->phone,
                        "💬 *Message client sur votre commande {$order->order_number}*\n"
                        ."👤 {$order->buyer_name} : ".trim($validated['body'])."\n\n"
                        ."Répondez dans votre espace MboaCar → Commandes reçues."
                    );
                } catch (\Throwable) {
                    // non-blocking
                }
            }
        }

        return response()->json([
            'ok' => true,
            'message' => [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'sender_name' => $message->sender_name,
                'body' => $message->body,
                'created_at' => $message->created_at?->format('d/m H:i'),
            ],
        ]);
    }
}
