<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Country;
use App\Models\MarketplaceOrderItem;
use App\Models\MarketplaceOrderMessage;
use App\Models\Product;
use App\Models\SubCategory;
use App\Services\GreenApiService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceManagementController extends Controller
{
    public function categories(TenantContext $tenantContext): Response
    {
        $tenantId = $tenantContext->id();
        $categories = Category::availableForTenant($tenantId)
            ->with(['subCategories' => fn ($q) => $q->availableForTenant($tenantId)->orderBy('name')])
            ->orderBy('name')
            ->get();

        return Inertia::render('Tenant/Marketplace/Categories', [
            'categories' => $categories,
        ]);
    }

    public function storeCategory(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'icon' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $slug = Str::slug($data['name']);
        if (Category::where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(4));
        }

        Category::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'slug' => $slug,
            'icon' => $data['icon'] ?: 'bi-gear-wide-connected',
            'description' => $data['description'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('success', 'Catégorie créée avec succès.');
    }

    public function deleteCategory(Category $category): RedirectResponse
    {
        $category->delete();

        return back()->with('success', 'Catégorie supprimée.');
    }

    public function storeSubCategory(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('marketplace_categories', 'id')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $slug = Str::slug($data['name']);
        if (SubCategory::where('category_id', $data['category_id'])->where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(4));
        }

        SubCategory::create([
            'tenant_id' => $tenantId,
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'slug' => $slug,
            'is_active' => true,
        ]);

        return back()->with('success', 'Sous-catégorie ajoutée.');
    }

    public function deleteSubCategory(SubCategory $subCategory): RedirectResponse
    {
        $subCategory->delete();

        return back()->with('success', 'Sous-catégorie supprimée.');
    }

    public function products(TenantContext $tenantContext): Response
    {
        if (Country::count() === 0) {
            Country::seedDefaults();
        }

        $tenantId = $tenantContext->id();

        $products = Product::with(['category', 'subCategory'])
            ->latest('id')
            ->paginate(20)
            ->through(function (Product $p) {
                $imageUrls = collect($p->images ?? [])->map(fn ($path, $idx) => route('marketplace.product.image', [
                    'product' => $p->id,
                    'index' => $idx,
                ]))->values();

                return [
                    'id' => $p->id,
                    'title' => $p->title,
                    'sku' => $p->sku,
                    'brand' => $p->brand,
                    'vehicle_compatibility' => $p->vehicle_compatibility,
                    'vehicle_brand' => $p->vehicle_brand,
                    'vehicle_model' => $p->vehicle_model,
                    'vehicle_year_from' => $p->vehicle_year_from,
                    'vehicle_year_to' => $p->vehicle_year_to,
                    'condition' => $p->condition,
                    'price' => $p->price,
                    'stock_quantity' => $p->stock_quantity,
                    'country_code' => $p->country_code,
                    'category_id' => $p->category_id,
                    'sub_category_id' => $p->sub_category_id,
                    'category' => $p->category,
                    'sub_category' => $p->subCategory,
                    'description' => $p->description,
                    'is_published' => $p->is_published,
                    'views_count' => $p->views_count,
                    'image_urls' => $imageUrls,
                ];
            });

        return Inertia::render('Tenant/Marketplace/Products', [
            'products' => $products,
            'categories' => Category::availableForTenant($tenantId)
                ->with(['subCategories' => fn ($q) => $q->availableForTenant($tenantId)->orderBy('name')])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'countries' => Country::active()->orderBy('name')->get(['code', 'name']),
            'defaultCountry' => $tenantContext->getTenant()?->country ?? 'CM',
        ]);
    }

    public function storeProduct(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenantId = $tenantContext->id();

        $data = $request->validate([
            'category_id' => ['nullable', 'integer', Rule::exists('marketplace_categories', 'id')],
            'new_category_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'sub_category_id' => ['nullable', 'integer', Rule::exists('marketplace_sub_categories', 'id')],
            'new_sub_category_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'title' => ['required', 'string', 'min:2', 'max:180'],
            'sku' => ['nullable', 'string', 'max:80'],
            'brand' => ['nullable', 'string', 'max:100'],
            'vehicle_compatibility' => ['nullable', 'string', 'max:200'],
            'vehicle_brand' => ['nullable', 'string', 'max:80'],
            'vehicle_model' => ['nullable', 'string', 'max:80'],
            'vehicle_year_from' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'vehicle_year_to' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'condition' => ['required', Rule::in(['neuf', 'occasion', 'adaptable'])],
            'price' => ['required', 'integer', 'min:100'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'country_code' => ['required', 'string', 'size:2'],
            'description' => ['nullable', 'string', 'max:3000'],
            'images' => ['nullable', 'array'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $categoryId = $data['category_id'] ?? null;
        if (empty($categoryId) && ! empty($data['new_category_name'])) {
            $slug = Str::slug($data['new_category_name']);
            $cat = Category::availableForTenant($tenantId)->where('slug', $slug)->first();
            if (! $cat) {
                $cat = Category::create([
                    'tenant_id' => $tenantId,
                    'name' => trim($data['new_category_name']),
                    'slug' => $slug.'-'.Str::lower(Str::random(4)),
                    'icon' => 'bi-gear-wide-connected',
                    'is_active' => true,
                ]);
            }
            $categoryId = $cat->id;
        }

        if (! $categoryId) {
            return back()->withErrors(['category_id' => 'Veuillez sélectionner ou créer une catégorie.']);
        }

        $subCategoryId = $data['sub_category_id'] ?? null;
        if (empty($subCategoryId) && ! empty($data['new_sub_category_name'])) {
            $subSlug = Str::slug($data['new_sub_category_name']);
            $sub = SubCategory::availableForTenant($tenantId)
                ->where('category_id', $categoryId)
                ->where('slug', $subSlug)
                ->first();
            if (! $sub) {
                $sub = SubCategory::create([
                    'tenant_id' => $tenantId,
                    'category_id' => $categoryId,
                    'name' => trim($data['new_sub_category_name']),
                    'slug' => $subSlug.'-'.Str::lower(Str::random(4)),
                    'is_active' => true,
                ]);
            }
            $subCategoryId = $sub->id;
        }

        $imagePaths = [];
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        foreach ($request->file('images', []) as $file) {
            if ($file instanceof UploadedFile) {
                $realMime = $finfo->file($file->getRealPath()) ?: '';
                if (! in_array($realMime, $allowedMimes, true)) {
                    return back()->withErrors(['images' => 'Format d\'image non autorisé. Formats acceptés : JPEG, PNG, WebP.']);
                }
                $imagePaths[] = Storage::disk('local')->putFile("marketplace/{$tenantId}", $file);
            }
        }

        // Supprime tout HTML pour empêcher toute attaque XSS stockée sur le Marketplace public
        $cleanDescription = ! empty($data['description'])
            ? trim(strip_tags($data['description']))
            : null;

        $product = Product::create([
            'tenant_id' => $tenantId,
            'category_id' => $categoryId,
            'sub_category_id' => $subCategoryId ?: null,
            'title' => $data['title'],
            'slug' => Str::slug($data['title']).'-'.Str::lower(Str::random(5)),
            'sku' => $data['sku'] ?? null,
            'brand' => $data['brand'] ?? null,
            'vehicle_compatibility' => $data['vehicle_compatibility'] ?? null,
            'vehicle_brand' => $data['vehicle_brand'] ?? null,
            'vehicle_model' => $data['vehicle_model'] ?? null,
            'vehicle_year_from' => $data['vehicle_year_from'] ?? null,
            'vehicle_year_to' => $data['vehicle_year_to'] ?? null,
            'condition' => $data['condition'],
            'price' => $data['price'],
            'stock_quantity' => $data['stock_quantity'],
            'country_code' => strtoupper($data['country_code']),
            'description' => $cleanDescription,
            'images' => $imagePaths,
            'is_published' => true,
        ]);

        AuditLog::record('marketplace.product_created', subject: $product, actor: $request->user('web'), tenantId: $tenantId, request: $request);

        return back()->with('success', "Pièce « {$product->title} » publiée sur le Marketplace.");
    }

    public function toggleProduct(Product $product): RedirectResponse
    {
        $product->update(['is_published' => ! $product->is_published]);

        return back()->with('success', 'Statut de publication mis à jour.');
    }

    public function deleteProduct(Product $product): RedirectResponse
    {
        $product->delete();

        return back()->with('success', 'Produit retiré du Marketplace.');
    }

    public function orders(TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();
        $tenantId = $tenant->id;

        $orderItems = MarketplaceOrderItem::with(['order', 'product', 'messages'])
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->paginate(25)
            ->through(function (MarketplaceOrderItem $item) {
                $token = $item->order?->ensureTrackingToken();
                $item->setAttribute('tracking_url', $token ? url("/suivi-commande/{$token}") : null);
                $item->setAttribute('unread_buyer_messages', $item->messages
                    ->where('sender_type', MarketplaceOrderMessage::SENDER_BUYER)
                    ->where('is_read', false)
                    ->count());

                return $item;
            });

        MarketplaceOrderItem::where('tenant_id', $tenantId)
            ->where('is_seen_by_seller', false)
            ->update(['is_seen_by_seller' => true]);

        $shopStats = [
            'pendingOrders' => MarketplaceOrderItem::where('tenant_id', $tenantId)
                ->whereIn('seller_status', ['nouvelle', 'confirmee', 'expediee'])
                ->count(),
            'deliveredOrders' => MarketplaceOrderItem::where('tenant_id', $tenantId)
                ->where('seller_status', 'livree')
                ->count(),
            'cancelledOrders' => MarketplaceOrderItem::where('tenant_id', $tenantId)
                ->where('seller_status', 'annulee')
                ->count(),
            'soldUnits' => (int) MarketplaceOrderItem::where('tenant_id', $tenantId)
                ->where('seller_status', 'livree')
                ->sum('quantity'),
            'soldRevenue' => (int) MarketplaceOrderItem::where('tenant_id', $tenantId)
                ->where('seller_status', 'livree')
                ->sum('subtotal'),
        ];

        return Inertia::render('Tenant/Marketplace/Orders', [
            'orderItems' => $orderItems,
            'shopStats' => $shopStats,
            'tenantLocation' => [
                'name' => $tenant->name,
                'latitude' => $tenant->latitude ? (float) $tenant->latitude : null,
                'longitude' => $tenant->longitude ? (float) $tenant->longitude : null,
                'formatted_location' => $tenant->formatted_location ?: $tenant->city,
            ],
        ]);
    }

    public function showOrder(MarketplaceOrderItem $orderItem, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();
        if ((int) $orderItem->tenant_id !== (int) $tenant->id) {
            abort(403);
        }

        $orderItem->load(['order', 'product', 'messages']);

        if (! $orderItem->is_seen_by_seller) {
            $orderItem->update(['is_seen_by_seller' => true]);
        }

        MarketplaceOrderMessage::where('marketplace_order_item_id', $orderItem->id)
            ->where('sender_type', MarketplaceOrderMessage::SENDER_BUYER)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $token = $orderItem->order?->ensureTrackingToken();

        return Inertia::render('Tenant/Marketplace/OrderShow', [
            'orderItem' => [
                'id' => $orderItem->id,
                'product_id' => $orderItem->marketplace_product_id,
                'product_title' => $orderItem->product_title,
                'unit_price' => $orderItem->unit_price,
                'quantity' => $orderItem->quantity,
                'subtotal' => $orderItem->subtotal,
                'seller_status' => $orderItem->seller_status,
                'created_at_formatted' => $orderItem->created_at?->format('d/m/Y à H:i'),
                'tracking_url' => $token ? url("/suivi-commande/{$token}") : null,
                'order' => $orderItem->order ? [
                    'id' => $orderItem->order->id,
                    'order_number' => $orderItem->order->order_number,
                    'buyer_name' => $orderItem->order->buyer_name,
                    'buyer_email' => $orderItem->order->buyer_email,
                    'country_code' => $orderItem->order->country_code,
                    'dial_code' => $orderItem->order->dial_code,
                    'buyer_whatsapp' => $orderItem->order->buyer_whatsapp,
                    'delivery_address' => $orderItem->order->delivery_address,
                    'delivery_latitude' => $orderItem->order->delivery_latitude ? (float) $orderItem->order->delivery_latitude : null,
                    'delivery_longitude' => $orderItem->order->delivery_longitude ? (float) $orderItem->order->delivery_longitude : null,
                    'notes' => $orderItem->order->notes,
                ] : null,
                'messages' => $orderItem->messages->map(fn (MarketplaceOrderMessage $m) => [
                    'id' => $m->id,
                    'sender_type' => $m->sender_type,
                    'sender_name' => $m->sender_name,
                    'body' => $m->body,
                    'created_at' => $m->created_at?->format('d/m H:i'),
                ])->values(),
            ],
            'tenantLocation' => [
                'name' => $tenant->name,
                'latitude' => $tenant->latitude ? (float) $tenant->latitude : null,
                'longitude' => $tenant->longitude ? (float) $tenant->longitude : null,
                'formatted_location' => $tenant->formatted_location ?: $tenant->city,
            ],
        ]);
    }

    public function updateOrderStatus(
        Request $request,
        MarketplaceOrderItem $orderItem,
        TenantContext $tenantContext,
        GreenApiService $greenApi
    ): RedirectResponse {
        $tenant = $tenantContext->getTenant();
        if ((int) $orderItem->tenant_id !== (int) $tenant->id) {
            abort(403);
        }

        $data = $request->validate([
            'seller_status' => ['required', Rule::in(['confirmee', 'expediee', 'livree', 'annulee'])],
        ]);

        $nextStatus = $data['seller_status'];

        $result = DB::transaction(function () use ($orderItem, $tenant, $nextStatus) {
            /** @var MarketplaceOrderItem $lockedItem */
            $lockedItem = MarketplaceOrderItem::whereKey($orderItem->id)->lockForUpdate()->firstOrFail();

            if (in_array($lockedItem->seller_status, ['livree', 'annulee'], true)) {
                return ['locked' => true, 'item' => $lockedItem];
            }

            if ($lockedItem->seller_status === $nextStatus) {
                return ['unchanged' => true, 'item' => $lockedItem];
            }

            $lockedItem->update([
                'seller_status' => $nextStatus,
                'is_seen_by_seller' => true,
            ]);

            // Si la commande est annulée : restituer la quantité commandée au stock du produit
            if ($nextStatus === 'annulee' && $lockedItem->marketplace_product_id) {
                Product::where('id', $lockedItem->marketplace_product_id)
                    ->where('tenant_id', $tenant->id)
                    ->increment('stock_quantity', (int) $lockedItem->quantity);
            }

            return ['locked' => false, 'unchanged' => false, 'item' => $lockedItem->fresh('order')];
        });

        if (! empty($result['locked'])) {
            return back()->with('error', 'Cette commande est déjà clôturée (livrée ou annulée) et ne peut plus être modifiée.');
        }

        if (! empty($result['unchanged'])) {
            return back();
        }

        /** @var MarketplaceOrderItem $item */
        $item = $result['item'];
        $order = $item->order;
        $token = $order?->ensureTrackingToken();
        $trackingUrl = $token ? url("/suivi-commande/{$token}") : url('/marketplace');
        $buyerPhone = $this->formatBuyerWhatsapp($order);
        $buyerName = $order?->buyer_name ?: 'Client';
        $orderNumber = $order?->order_number ?: ('#'.$item->id);
        $amountFmt = number_format((int) $item->subtotal, 0, ',', ' ');

        if ($nextStatus === 'confirmee') {
            MarketplaceOrderMessage::create([
                'marketplace_order_item_id' => $item->id,
                'sender_type' => MarketplaceOrderMessage::SENDER_SELLER,
                'sender_name' => $tenant->name,
                'body' => "✅ Commande confirmée ! {$tenant->name} prépare votre article ({$item->product_title} × {$item->quantity}).",
                'is_read' => false,
            ]);

            if ($buyerPhone !== '') {
                try {
                    $wa = "✅ *COMMANDE CONFIRMÉE SUR MBOACAR !*\n\n"
                        ."Bonjour {$buyerName},\n"
                        ."Bonne nouvelle ! *{$tenant->name}* vient de confirmer votre commande *{$orderNumber}*.\n\n"
                        ."📦 *Article :* {$item->product_title} (x{$item->quantity})\n"
                        ."💰 *Montant :* {$amountFmt} FCFA\n"
                        ."📍 *Livraison :* ".($order?->delivery_address ?: 'Votre adresse')."\n\n"
                        ."💬 Suivez votre commande et échangez directement avec le vendeur ici :\n{$trackingUrl}";
                    $greenApi->sendMessage($buyerPhone, $wa);
                } catch (\Throwable) {
                    // non-blocking
                }
            }

            AuditLog::record('marketplace.order_confirmed', subject: $item, actor: $request->user('web'), tenantId: $tenant->id, request: $request);

            return back()->with('success', 'Commande confirmée ! Un message WhatsApp de confirmation a été envoyé au client.');
        }

        if ($nextStatus === 'annulee') {
            MarketplaceOrderMessage::create([
                'marketplace_order_item_id' => $item->id,
                'sender_type' => MarketplaceOrderMessage::SENDER_SELLER,
                'sender_name' => $tenant->name,
                'body' => "❌ Commande annulée par {$tenant->name}. Le stock du produit a été remis à jour.",
                'is_read' => false,
            ]);

            if ($buyerPhone !== '') {
                try {
                    $wa = "❌ *ANNULATION DE COMMANDE MBOACAR*\n\n"
                        ."Bonjour {$buyerName},\n"
                        ."Votre commande *{$orderNumber}* ({$item->product_title} × {$item->quantity}) auprès de *{$tenant->name}* a été annulée.\n\n"
                        ."Vous pouvez consulter d'autres pièces disponibles sur le Marketplace :\n".url('/marketplace');
                    $greenApi->sendMessage($buyerPhone, $wa);
                } catch (\Throwable) {
                    // non-blocking
                }
            }

            AuditLog::record('marketplace.order_cancelled', subject: $item, actor: $request->user('web'), tenantId: $tenant->id, metadata: [
                'restored_quantity' => $item->quantity,
                'product_id' => $item->marketplace_product_id,
            ], request: $request);

            return back()->with('success', "Commande annulée et +{$item->quantity} unité(s) remise(s) en stock sur le produit.");
        }

        if ($nextStatus === 'livree') {
            MarketplaceOrderMessage::create([
                'marketplace_order_item_id' => $item->id,
                'sender_type' => MarketplaceOrderMessage::SENDER_SELLER,
                'sender_name' => $tenant->name,
                'body' => "🎉 Commande livrée ! Merci infiniment pour votre achat et votre confiance chez {$tenant->name}.",
                'is_read' => false,
            ]);

            if ($buyerPhone !== '') {
                try {
                    $wa = "🎉 *MERCI POUR VOTRE ACHAT CHEZ {$tenant->name} !*\n\n"
                        ."Bonjour {$buyerName},\n"
                        ."Votre commande *{$orderNumber}* a bien été livrée :\n"
                        ."📦 *Article :* {$item->product_title} (x{$item->quantity})\n"
                        ."💰 *Total :* {$amountFmt} FCFA\n\n"
                        ."Toute l'équipe de *{$tenant->name}* vous remercie chaleureusement pour votre confiance ! 🙏\n"
                        ."N'hésitez pas à revenir vers nous pour vos prochains besoins automobiles.\n\n"
                        ."👉 Récapitulatif de votre commande :\n{$trackingUrl}";
                    $greenApi->sendMessage($buyerPhone, $wa);
                } catch (\Throwable) {
                    // non-blocking
                }
            }

            AuditLog::record('marketplace.order_delivered', subject: $item, actor: $request->user('web'), tenantId: $tenant->id, metadata: [
                'subtotal' => $item->subtotal,
                'quantity' => $item->quantity,
            ], request: $request);

            return back()->with('success', 'Commande marquée comme livrée & vendue ! Un message de remerciement a été envoyé au client et la vente est comptabilisée dans vos statistiques.');
        }

        return back()->with('success', 'Statut de la commande mis à jour.');
    }

    /**
     * Bouton "Départ" : passe la commande en "expediee" (en route) et notifie immédiatement le client sur WhatsApp
     * avec le lien de suivi et de chat direct Client ↔ Vendeur.
     */
    public function departOrder(
        MarketplaceOrderItem $orderItem,
        TenantContext $tenantContext,
        GreenApiService $greenApi
    ): RedirectResponse {
        $tenant = $tenantContext->getTenant();
        if ((int) $orderItem->tenant_id !== (int) $tenant->id) {
            abort(403);
        }

        if (in_array($orderItem->seller_status, ['livree', 'annulee'], true)) {
            return back()->with('error', 'Cette commande est déjà clôturée (livrée ou annulée) et ne peut plus être modifiée.');
        }

        $orderItem->load('order');
        $order = $orderItem->order;

        $orderItem->update([
            'seller_status' => 'expediee',
            'is_seen_by_seller' => true,
        ]);

        $token = $order?->ensureTrackingToken();
        $trackingUrl = $token ? url("/suivi-commande/{$token}") : url('/marketplace');

        MarketplaceOrderMessage::create([
            'marketplace_order_item_id' => $orderItem->id,
            'sender_type' => MarketplaceOrderMessage::SENDER_SELLER,
            'sender_name' => $tenant->name,
            'body' => '🚚 Départ confirmé ! Je suis déjà en chemin pour vous livrer votre commande.',
            'is_read' => false,
        ]);

        $buyerPhone = $this->formatBuyerWhatsapp($order);
        if ($buyerPhone !== '') {
            try {
                $msg = "🚚 *VOTRE COMMANDE MBOACAR EST EN CHEMIN !*\n\n"
                    ."🏪 *Vendeur :* {$tenant->name}\n"
                    ."📦 *Article :* {$orderItem->product_title} (x{$orderItem->quantity})\n"
                    ."📍 *Destination :* ".($order?->delivery_address ?: 'Votre adresse')."\n\n"
                    ."Le vendeur vient de lancer le départ vers vous.\n"
                    ."💬 Suivez votre livraison et discutez directement avec lui ici :\n{$trackingUrl}";
                $greenApi->sendMessage($buyerPhone, $msg);
            } catch (\Throwable) {
                // non-blocking
            }
        }

        return back()->with('success', 'Départ signalé ! Le client vient de recevoir un message WhatsApp avec son lien de suivi et de chat.');
    }

    /**
     * Envoie un message personnalisé au client (chat commande + WhatsApp optionnel).
     */
    public function sendOrderMessage(
        Request $request,
        MarketplaceOrderItem $orderItem,
        TenantContext $tenantContext,
        GreenApiService $greenApi
    ): JsonResponse {
        $tenant = $tenantContext->getTenant();
        if ((int) $orderItem->tenant_id !== (int) $tenant->id) {
            abort(403);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:1500'],
            'send_whatsapp' => ['nullable', 'boolean'],
        ]);

        $orderItem->load('order');
        $order = $orderItem->order;
        $token = $order?->ensureTrackingToken();
        $trackingUrl = $token ? url("/suivi-commande/{$token}") : url('/marketplace');

        $message = MarketplaceOrderMessage::create([
            'marketplace_order_item_id' => $orderItem->id,
            'sender_type' => MarketplaceOrderMessage::SENDER_SELLER,
            'sender_name' => $tenant->name,
            'body' => trim($validated['body']),
            'is_read' => false,
        ]);

        if (! empty($validated['send_whatsapp'])) {
            $buyerPhone = $this->formatBuyerWhatsapp($order);
            if ($buyerPhone !== '') {
                try {
                    $wa = "💬 *Message de {$tenant->name} (Commande {$order?->order_number})*\n\n"
                        .trim($validated['body'])
                        ."\n\nRépondez directement ici : {$trackingUrl}";
                    $greenApi->sendMessage($buyerPhone, $wa);
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

    /**
     * Flux JSON des messages d'une commande (interrogé toutes les 5s dans le modal Détails).
     */
    public function orderMessages(MarketplaceOrderItem $orderItem, TenantContext $tenantContext): JsonResponse
    {
        if ((int) $orderItem->tenant_id !== (int) $tenantContext->id()) {
            abort(403);
        }

        MarketplaceOrderMessage::where('marketplace_order_item_id', $orderItem->id)
            ->where('sender_type', MarketplaceOrderMessage::SENDER_BUYER)
            ->where('is_read', false)
            ->update(['is_read' => true]);

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

        return response()->json(['ok' => true, 'messages' => $messages]);
    }

    private function formatBuyerWhatsapp(?\App\Models\MarketplaceOrder $order): string
    {
        if (! $order || empty($order->buyer_whatsapp)) {
            return '';
        }
        $raw = preg_replace('/\s+/', '', (string) $order->buyer_whatsapp);
        if (str_starts_with($raw, '+')) {
            return $raw;
        }
        $dial = trim((string) ($order->dial_code ?: '+237'));

        return $dial.$raw;
    }
}
