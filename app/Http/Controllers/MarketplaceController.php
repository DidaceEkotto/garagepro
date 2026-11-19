<?php

namespace App\Http\Controllers;

use App\Mail\MarketplaceNewOrderMail;
use App\Models\Category;
use App\Models\Country;
use App\Models\MarketplaceBanner;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\PlatformMessage;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\SubCategory;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketplaceController extends Controller
{
    public function index(Request $request): Response
    {
        if (Country::count() === 0) {
            Country::seedDefaults();
        }

        $search = trim((string) $request->query('q', ''));
        $categoryId = $request->query('category');
        $subCategoryId = $request->query('sub_category');
        $countryCode = $request->query('country');
        $condition = $request->query('condition');
        $vehicleBrand = trim((string) $request->query('vehicle_brand', ''));
        $vehicleModel = trim((string) $request->query('vehicle_model', ''));
        $vehicleYear = $request->query('vehicle_year');
        $sort = $request->query('sort', 'recent');

        $query = Product::withoutTenantScope()
            ->with(['category', 'subCategory', 'tenant:id,name,city,country', 'country:code,name'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->where('is_published', true)
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%")
                        ->orWhere('vehicle_compatibility', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($subCategoryId, fn ($q) => $q->where('sub_category_id', $subCategoryId))
            ->when($countryCode, fn ($q) => $q->where('country_code', strtoupper((string) $countryCode)))
            ->when($condition, fn ($q) => $q->where('condition', $condition))
            ->when($vehicleBrand !== '', function ($q) use ($vehicleBrand) {
                $q->where(function ($sub) use ($vehicleBrand) {
                    $sub->where('vehicle_brand', 'like', "%{$vehicleBrand}%")
                        ->orWhere('vehicle_compatibility', 'like', "%{$vehicleBrand}%");
                });
            })
            ->when($vehicleModel !== '', function ($q) use ($vehicleModel) {
                $q->where(function ($sub) use ($vehicleModel) {
                    $sub->where('vehicle_model', 'like', "%{$vehicleModel}%")
                        ->orWhere('vehicle_compatibility', 'like', "%{$vehicleModel}%");
                });
            })
            ->when($vehicleYear && is_numeric($vehicleYear), function ($q) use ($vehicleYear) {
                $y = (int) $vehicleYear;
                $q->where(function ($sub) use ($y) {
                    $sub->where(function ($range) use ($y) {
                        $range->whereNotNull('vehicle_year_from')
                            ->where('vehicle_year_from', '<=', $y)
                            ->where(function ($end) use ($y) {
                                $end->whereNull('vehicle_year_to')->orWhere('vehicle_year_to', '>=', $y);
                            });
                    })->orWhere('vehicle_compatibility', 'like', "%{$y}%");
                });
            });

        match ($sort) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            default => $query->latest('id'),
        };

        $products = $query->paginate(12)->withQueryString()->through(fn (Product $p) => $this->transformProduct($p));

        $categories = Category::with(['subCategories' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $hasActiveFilters = $search !== '' || $categoryId || $subCategoryId || $countryCode || $condition || $vehicleBrand !== '' || $vehicleModel !== '' || ($vehicleYear !== null && $vehicleYear !== '');

        $bestSellers = Product::withoutTenantScope()
            ->with(['category', 'subCategory', 'tenant:id,name,city,country', 'country:code,name'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->withSum('orderItems as sold_quantity', 'quantity')
            ->where('is_published', true)
            ->orderByDesc('sold_quantity')
            ->orderByDesc('views_count')
            ->latest('id')
            ->take(4)
            ->get()
            ->map(fn (Product $p) => $this->transformProduct($p))
            ->values();

        $newProducts = Product::withoutTenantScope()
            ->with(['category', 'subCategory', 'tenant:id,name,city,country', 'country:code,name'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->where('is_published', true)
            ->latest('id')
            ->take(4)
            ->get()
            ->map(fn (Product $p) => $this->transformProduct($p))
            ->values();

        $banners = MarketplaceBanner::active()
            ->latest('id')
            ->get()
            ->groupBy('placement')
            ->map(function ($group) {
                $b = $group->first();

                return [
                    'id' => $b->id,
                    'title' => $b->title,
                    'subtitle' => $b->subtitle,
                    'placement' => $b->placement,
                    'image_url' => $b->resolvedImageUrl(),
                    'gradient_from' => $b->gradient_from ?: '#0f172a',
                    'gradient_to' => $b->gradient_to ?: '#2563eb',
                    'button_text' => $b->button_text ?: 'Découvrir',
                    'button_url' => $b->button_url,
                    'is_external' => $b->isExternalUrl(),
                ];
            });

        return Inertia::render('Marketplace/Index', [
            'products' => $products,
            'bestSellers' => $bestSellers,
            'newProducts' => $newProducts,
            'banners' => $banners,
            'hasActiveFilters' => $hasActiveFilters,
            'categories' => $categories,
            'countries' => Country::active()->orderBy('name')->get(['code', 'name']),
            'isBestSellers' => false,
            'filters' => [
                'q' => $search,
                'category' => $categoryId ? (int) $categoryId : '',
                'sub_category' => $subCategoryId ? (int) $subCategoryId : '',
                'country' => $countryCode ?: '',
                'condition' => $condition ?: '',
                'vehicle_brand' => $vehicleBrand,
                'vehicle_model' => $vehicleModel,
                'vehicle_year' => $vehicleYear ?: '',
                'sort' => $sort,
            ],
        ]);
    }

    public function bestSellers(Request $request): Response
    {
        if (Country::count() === 0) {
            Country::seedDefaults();
        }

        $countryCode = $request->query('country');

        $products = Product::withoutTenantScope()
            ->with(['category', 'subCategory', 'tenant:id,name,city,country', 'country:code,name'])
            ->withSum('orderItems as sold_quantity', 'quantity')
            ->where('is_published', true)
            ->when($countryCode, fn ($q) => $q->where('country_code', strtoupper((string) $countryCode)))
            ->orderByDesc('sold_quantity')
            ->orderByDesc('views_count')
            ->latest('id')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Product $p) => $this->transformProduct($p));

        $categories = Category::with(['subCategories' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('Marketplace/Index', [
            'products' => $products,
            'categories' => $categories,
            'countries' => Country::active()->orderBy('name')->get(['code', 'name']),
            'isBestSellers' => true,
            'filters' => [
                'q' => '',
                'category' => '',
                'sub_category' => '',
                'country' => $countryCode ?: '',
                'condition' => '',
                'sort' => 'recent',
            ],
        ]);
    }

    public function category(Request $request, string $categorySlug, ?string $subCategorySlug = null): Response
    {
        $category = Category::with(['subCategories' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->where('slug', $categorySlug)
            ->firstOrFail();

        $subCategory = null;
        if ($subCategorySlug) {
            $subCategory = SubCategory::where('category_id', $category->id)
                ->where('slug', $subCategorySlug)
                ->firstOrFail();
        }

        $countryCode = $request->query('country');
        $inStock = $request->boolean('in_stock');
        $condition = $request->query('condition');
        $minPrice = $request->query('min_price');
        $maxPrice = $request->query('max_price');
        $sort = $request->query('sort', 'recent');

        $baseQuery = Product::withoutTenantScope()
            ->where('is_published', true)
            ->where('category_id', $category->id)
            ->when($subCategory, fn ($q) => $q->where('sub_category_id', $subCategory->id));

        $priceStats = (clone $baseQuery)
            ->selectRaw('COALESCE(MIN(price), 0) as min_price, COALESCE(MAX(price), 0) as max_price')
            ->first();

        $boundMin = (int) floor((float) ($priceStats->min_price ?? 0));
        $boundMax = (int) ceil((float) ($priceStats->max_price ?? 0));
        if ($boundMax <= $boundMin) {
            $boundMax = max($boundMin + 10000, 500000);
        }

        $query = (clone $baseQuery)
            ->with(['category', 'subCategory', 'tenant:id,name,city,country', 'country:code,name'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->when($countryCode, fn ($q) => $q->where('country_code', strtoupper((string) $countryCode)))
            ->when($inStock, fn ($q) => $q->where('stock_quantity', '>', 0))
            ->when($condition, fn ($q) => $q->where('condition', $condition))
            ->when($minPrice !== null && $minPrice !== '', fn ($q) => $q->where('price', '>=', (float) $minPrice))
            ->when($maxPrice !== null && $maxPrice !== '', fn ($q) => $q->where('price', '<=', (float) $maxPrice));

        match ($sort) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            default => $query->latest('id'),
        };

        $products = $query->paginate(12)
            ->withQueryString()
            ->through(fn (Product $p) => $this->transformProduct($p));

        return Inertia::render('Marketplace/Category', [
            'category' => $category,
            'subCategory' => $subCategory,
            'products' => $products,
            'countries' => Country::active()->orderBy('name')->get(['code', 'name']),
            'selectedCountry' => $countryCode ?: '',
            'priceBounds' => [
                'min' => $boundMin,
                'max' => $boundMax,
            ],
            'filters' => [
                'country' => $countryCode ?: '',
                'in_stock' => $inStock,
                'condition' => $condition ?: '',
                'min_price' => $minPrice !== null && $minPrice !== '' ? (int) $minPrice : $boundMin,
                'max_price' => $maxPrice !== null && $maxPrice !== '' ? (int) $maxPrice : $boundMax,
                'sort' => $sort,
            ],
        ]);
    }

    public function b2bSearch(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $tenantId = $tenantContext->id();

        $items = Product::withoutTenantScope()
            ->with(['tenant:id,name,city,phone'])
            ->where('is_published', true)
            ->where('stock_quantity', '>', 0)
            ->when($tenantId, fn ($query) => $query->where('tenant_id', '!=', $tenantId))
            ->where(function ($sub) use ($q) {
                $sub->where('title', 'like', "%{$q}%")
                    ->orWhere('brand', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "%{$q}%")
                    ->orWhere('vehicle_compatibility', 'like', "%{$q}%");
            })
            ->orderByDesc('stock_quantity')
            ->take(6)
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'title' => $p->title,
                'price' => $p->price,
                'stock_quantity' => $p->stock_quantity,
                'condition' => $p->condition,
                'garage_name' => $p->tenant?->name,
                'garage_city' => $p->tenant?->city,
                'url' => route('marketplace.show', ['product' => $p->slug]),
            ])
            ->values();

        return response()->json(['items' => $items]);
    }

    public function show(string $product): Response|RedirectResponse
    {
        $item = Product::withoutTenantScope()
            ->with(['category', 'subCategory', 'tenant:id,name,city,country', 'country:code,name'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->where('is_published', true)
            ->where('slug', $product)
            ->first();

        if (! $item && ctype_digit($product)) {
            $byId = Product::withoutTenantScope()
                ->where('is_published', true)
                ->find((int) $product);
            if ($byId) {
                if (empty($byId->slug)) {
                    $byId->slug = Str::slug($byId->title ?: 'produit')."-{$byId->id}";
                    $byId->saveQuietly();
                }

                return redirect()->route('marketplace.show', ['product' => $byId->slug], 301);
            }
        }

        if (! $item) {
            abort(404);
        }

        $item->increment('views_count');

        $reviews = ProductReview::where('product_id', $item->id)
            ->latest('id')
            ->take(20)
            ->get(['id', 'reviewer_name', 'rating', 'comment', 'is_verified_purchase', 'created_at']);

        $breakdownRaw = ProductReview::where('product_id', $item->id)
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating')
            ->all();

        $ratingBreakdown = [];
        for ($star = 5; $star >= 1; $star--) {
            $ratingBreakdown[$star] = (int) ($breakdownRaw[$star] ?? 0);
        }

        $related = Product::withoutTenantScope()
            ->with(['category', 'tenant:id,name,city', 'country:code,name'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->where('is_published', true)
            ->where('id', '!=', $item->id)
            ->where('category_id', $item->category_id)
            ->latest('id')
            ->take(4)
            ->get()
            ->map(fn (Product $p) => $this->transformProduct($p));

        return Inertia::render('Marketplace/Show', [
            'product' => $this->transformProduct($item),
            'relatedProducts' => $related,
            'reviews' => $reviews,
            'ratingBreakdown' => $ratingBreakdown,
        ]);
    }

    public function storeReview(Request $request, string $product): RedirectResponse
    {
        $item = Product::withoutTenantScope()
            ->where('is_published', true)
            ->where('slug', $product)
            ->firstOrFail();

        // Empêcher un vendeur de noter son propre produit
        $webUser = $request->user('web');
        if ($webUser && (int) $webUser->tenant_id === (int) $item->tenant_id) {
            return back()->with('error', 'Vous ne pouvez pas évaluer votre propre produit.');
        }

        $data = $request->validate([
            'reviewer_name' => ['required', 'string', 'min:2', 'max:100'],
            'reviewer_email' => ['required', 'email:rfc', 'max:160'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'order_number' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'max:0'], // Honeypot anti-bot
        ]);

        $email = strtolower(trim($data['reviewer_email']));
        $ipHash = hash('sha256', ($request->ip() ?: '127.0.0.1').'|'.config('app.key'));

        if (ProductReview::where('product_id', $item->id)->where('reviewer_email', $email)->exists()) {
            return back()->with('error', 'Vous avez déjà soumis un avis pour ce produit avec cette adresse e-mail.');
        }

        if (ProductReview::where('product_id', $item->id)
            ->where('ip_hash', $ipHash)
            ->where('created_at', '>=', now()->subHours(24))
            ->exists()) {
            return back()->with('error', 'Une évaluation a déjà été enregistrée récemment depuis votre connexion pour ce produit.');
        }

        // Vérification d'achat réel (email ou numéro de commande)
        $matchingOrder = MarketplaceOrder::whereHas('items', function ($q) use ($item) {
            $q->where('marketplace_product_id', $item->id);
        })->where(function ($q) use ($email, $data) {
            $q->where('buyer_email', $email);
            if (! empty($data['order_number'])) {
                $q->orWhere('order_number', trim($data['order_number']));
            }
        })->first();

        ProductReview::create([
            'product_id' => $item->id,
            'order_id' => $matchingOrder?->id,
            'reviewer_name' => strip_tags(trim($data['reviewer_name'])),
            'reviewer_email' => $email,
            'rating' => (int) $data['rating'],
            'comment' => ! empty($data['comment']) ? strip_tags(trim($data['comment'])) : null,
            'is_verified_purchase' => $matchingOrder !== null,
            'ip_hash' => $ipHash,
        ]);

        return back()->with('success', $matchingOrder
            ? 'Merci ! Votre avis (Achat vérifié) a été publié.'
            : 'Merci ! Votre avis a été publié avec succès.');
    }

    public function image(int $product, int $index): StreamedResponse|BinaryFileResponse
    {
        $item = Product::withoutTenantScope()->findOrFail($product);
        $images = $item->images ?? [];
        if (! isset($images[$index])) {
            abort(404);
        }

        $path = $images[$index];
        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->response($path);
    }

    public function bannerImage(int $banner): StreamedResponse|BinaryFileResponse
    {
        $item = MarketplaceBanner::findOrFail($banner);
        if (empty($item->image_path) || ! Storage::disk('local')->exists($item->image_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($item->image_path);
    }

    public function apiSearch(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 3) {
            return response()->json([
                'min_length_required' => true,
                'items' => [],
            ]);
        }

        $items = Product::withoutTenantScope()
            ->with(['category', 'tenant:id,name,city', 'country:code,name'])
            ->where('is_published', true)
            ->where(function ($sub) use ($q) {
                $sub->where('title', 'like', "%{$q}%")
                    ->orWhere('brand', 'like', "%{$q}%")
                    ->orWhere('vehicle_compatibility', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "%{$q}%");
            })
            ->latest('id')
            ->take(12)
            ->get()
            ->map(fn (Product $p) => $this->transformProduct($p))
            ->values();

        return response()->json([
            'min_length_required' => false,
            'items' => $items,
        ]);
    }

    public function cart(): Response
    {
        $cart = session('marketplace_cart', []);
        $items = [];
        $total = 0;

        if (! empty($cart)) {
            $products = Product::withoutTenantScope()
                ->with(['tenant:id,name', 'country:code,name'])
                ->whereIn('id', array_keys($cart))
                ->where('is_published', true)
                ->get()
                ->keyBy('id');

            foreach ($cart as $id => $qty) {
                $p = $products->get((int) $id);
                if (! $p) {
                    continue;
                }
                $qty = max(1, (int) $qty);
                $subtotal = $p->price * $qty;
                $total += $subtotal;
                $items[] = [
                    ...$this->transformProduct($p),
                    'quantity' => $qty,
                    'subtotal' => $subtotal,
                ];
            }
        }

        return Inertia::render('Marketplace/Cart', [
            'cartItems' => $items,
            'total' => $total,
        ]);
    }

    public function addToCart(Request $request, int $product): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $qty = max(1, (int) $request->input('quantity', 1));
        $increment = $request->boolean('increment', false);
        $cart = session('marketplace_cart', []);

        $result = DB::transaction(function () use ($product, $qty, $increment, &$cart) {
            $item = Product::withoutTenantScope()
                ->where('is_published', true)
                ->lockForUpdate()
                ->findOrFail($product);

            $alreadyInCart = isset($cart[$item->id]);
            if ($alreadyInCart && ! $increment) {
                return ['status' => 'duplicate', 'item' => $item];
            }

            if ($item->stock_quantity < $qty) {
                return ['status' => 'insufficient_stock', 'item' => $item];
            }

            $item->decrement('stock_quantity', $qty);
            $cart[$item->id] = ($cart[$item->id] ?? 0) + $qty;
            session(['marketplace_cart' => $cart]);

            return ['status' => $alreadyInCart ? 'incremented' : 'added', 'item' => $item];
        });

        /** @var Product $item */
        $item = $result['item'];

        if ($result['status'] === 'duplicate') {
            $msg = "« {$item->title} » est déjà dans votre panier. Voulez-vous augmenter la quantité ?";
            if ($request->expectsJson()) {
                return response()->json([
                    'already_in_cart' => true,
                    'product_id' => $item->id,
                    'message' => $msg,
                    'cart' => $this->buildCartSummary(),
                ], 409);
            }

            return back()->with('warning', $msg);
        }

        if ($result['status'] === 'insufficient_stock') {
            $msg = "Stock insuffisant pour « {$item->title} » (reste {$item->stock_quantity}).";
            if ($request->expectsJson()) {
                return response()->json(['message' => $msg, 'cart' => $this->buildCartSummary()], 422);
            }

            return back()->with('error', $msg);
        }

        $successMsg = $result['status'] === 'incremented'
            ? "Quantité de « {$item->title} » augmentée dans le panier."
            : "« {$item->title} » ajouté au panier.";

        if ($request->expectsJson()) {
            return response()->json([
                'already_in_cart' => false,
                'message' => $successMsg,
                'cart' => $this->buildCartSummary(),
            ]);
        }

        return back()->with('success', $successMsg);
    }

    public function updateCart(Request $request, int $productId): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $newQty = (int) $request->input('quantity', 1);
        $cart = session('marketplace_cart', []);
        $oldQty = (int) ($cart[$productId] ?? 0);

        $errorMsg = DB::transaction(function () use ($productId, $newQty, $oldQty, &$cart) {
            $item = Product::withoutTenantScope()->lockForUpdate()->find($productId);
            if (! $item) {
                unset($cart[$productId]);
                session(['marketplace_cart' => $cart]);

                return null;
            }

            if ($newQty <= 0) {
                if ($oldQty > 0) {
                    $item->increment('stock_quantity', $oldQty);
                }
                unset($cart[$productId]);
                session(['marketplace_cart' => $cart]);

                return null;
            }

            $diff = $newQty - $oldQty;
            if ($diff > 0) {
                if ($item->stock_quantity < $diff) {
                    return "Stock insuffisant pour « {$item->title} » (reste {$item->stock_quantity}).";
                }
                $item->decrement('stock_quantity', $diff);
            } elseif ($diff < 0) {
                $item->increment('stock_quantity', abs($diff));
            }

            $cart[$productId] = $newQty;
            session(['marketplace_cart' => $cart]);

            return null;
        });

        if ($errorMsg) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $errorMsg, 'cart' => $this->buildCartSummary()], 422);
            }

            return back()->with('error', $errorMsg);
        }

        if ($request->expectsJson()) {
            return response()->json(['cart' => $this->buildCartSummary()]);
        }

        return back();
    }

    public function removeFromCart(Request $request, int $productId): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $cart = session('marketplace_cart', []);
        $oldQty = (int) ($cart[$productId] ?? 0);

        DB::transaction(function () use ($productId, $oldQty, &$cart) {
            if ($oldQty > 0) {
                $item = Product::withoutTenantScope()->lockForUpdate()->find($productId);
                if ($item) {
                    $item->increment('stock_quantity', $oldQty);
                }
            }
            unset($cart[$productId]);
            session(['marketplace_cart' => $cart]);
        });

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Article retiré du panier et stock restauré.',
                'cart' => $this->buildCartSummary(),
            ]);
        }

        return back()->with('success', 'Article retiré du panier et stock restauré.');
    }

    public function buildCartSummary(): array
    {
        $cart = session('marketplace_cart', []);
        if (empty($cart)) {
            return ['items' => [], 'count' => 0, 'total' => 0];
        }

        $products = Product::withoutTenantScope()
            ->with(['tenant:id,name', 'country:code,name'])
            ->whereIn('id', array_keys($cart))
            ->where('is_published', true)
            ->get()
            ->keyBy('id');

        $items = [];
        $total = 0;
        $count = 0;
        foreach ($cart as $id => $qty) {
            $p = $products->get((int) $id);
            if (! $p) {
                continue;
            }
            $qty = max(1, (int) $qty);
            $subtotal = $p->price * $qty;
            $total += $subtotal;
            $count += $qty;
            $items[] = [
                ...$this->transformProduct($p),
                'quantity' => $qty,
                'subtotal' => $subtotal,
            ];
        }

        return [
            'items' => $items,
            'count' => $count,
            'total' => $total,
        ];
    }

    public function checkout(): Response|RedirectResponse
    {
        if (Country::count() === 0) {
            Country::seedDefaults();
        }

        $cart = session('marketplace_cart', []);
        if (empty($cart)) {
            return redirect()->route('marketplace.index')->with('info', 'Votre panier est vide.');
        }

        $products = Product::withoutTenantScope()
            ->with('tenant:id,name,city')
            ->whereIn('id', array_keys($cart))
            ->where('is_published', true)
            ->get()
            ->keyBy('id');

        $items = [];
        $total = 0;
        foreach ($cart as $id => $qty) {
            $p = $products->get((int) $id);
            if (! $p) {
                continue;
            }
            $qty = max(1, (int) $qty);
            $subtotal = $p->price * $qty;
            $total += $subtotal;
            $items[] = [
                'id' => $p->id,
                'title' => $p->title,
                'price' => $p->price,
                'quantity' => $qty,
                'subtotal' => $subtotal,
                'seller_id' => $p->tenant_id,
                'seller_name' => $p->tenant?->name ?? 'Garage',
                'image' => $this->transformProduct($p)['primary_image'],
            ];
        }

        return Inertia::render('Marketplace/Checkout', [
            'cartItems' => $items,
            'total' => $total,
            'countries' => Country::active()->orderBy('name')->get(['code', 'name', 'dial_code']),
        ]);
    }

    public function placeOrder(Request $request): RedirectResponse
    {
        $cart = session('marketplace_cart', []);
        if (empty($cart)) {
            return redirect()->route('marketplace.index')->with('error', 'Votre panier est vide.');
        }

        $data = $request->validate([
            'buyer_name' => ['required', 'string', 'min:2', 'max:150'],
            'buyer_email' => ['required', 'string', 'email', 'max:150'],
            'country_code' => ['required', 'string', 'size:2', Rule::exists('countries', 'code')->where('is_active', true)],
            'buyer_whatsapp' => ['required', 'string', 'min:6', 'max:35'],
            'delivery_address' => ['required', 'string', 'min:5', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $country = Country::where('code', strtoupper($data['country_code']))->firstOrFail();

        $orderData = DB::transaction(function () use ($cart, $data, $country) {
            $products = Product::withoutTenantScope()
                ->whereIn('id', array_keys($cart))
                ->where('is_published', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $total = 0;
            $lines = [];
            foreach ($cart as $id => $qty) {
                $p = $products->get((int) $id);
                if (! $p) {
                    continue;
                }
                $qty = max(1, (int) $qty);
                $subtotal = $p->price * $qty;
                $total += $subtotal;
                $lines[] = [
                    'product' => $p,
                    'quantity' => $qty,
                    'subtotal' => $subtotal,
                ];
            }

            $order = MarketplaceOrder::create([
                'order_number' => 'CMD-'.strtoupper(Str::random(7)),
                'buyer_name' => $data['buyer_name'],
                'buyer_email' => strtolower($data['buyer_email']),
                'country_code' => $country->code,
                'dial_code' => $country->dial_code,
                'buyer_whatsapp' => trim($data['buyer_whatsapp']),
                'delivery_address' => $data['delivery_address'],
                'notes' => $data['notes'] ?? null,
                'total_amount' => $total,
                'status' => 'nouvelle',
            ]);

            $itemsByTenant = [];
            foreach ($lines as $line) {
                /** @var Product $p */
                $p = $line['product'];
                MarketplaceOrderItem::create([
                    'marketplace_order_id' => $order->id,
                    'marketplace_product_id' => $p->id,
                    'tenant_id' => $p->tenant_id,
                    'product_title' => $p->title,
                    'unit_price' => $p->price,
                    'quantity' => $line['quantity'],
                    'subtotal' => $line['subtotal'],
                    'seller_status' => 'nouvelle',
                    'is_seen_by_seller' => false,
                ]);

                $itemsByTenant[$p->tenant_id][] = [
                    'title' => $p->title,
                    'quantity' => $line['quantity'],
                    'unit_price' => $p->price,
                    'subtotal' => $line['subtotal'],
                ];
            }

            return [$order, $itemsByTenant];
        });

        /** @var MarketplaceOrder $order */
        [$order, $itemsByTenant] = $orderData;

        // Notifier chaque vendeur par e-mail ET via son espace interne (PlatformMessage)
        foreach ($itemsByTenant as $tenantId => $sellerItems) {
            $seller = Tenant::find($tenantId);
            if (! $seller) {
                continue;
            }

            $sellerTotal = array_sum(array_column($sellerItems, 'subtotal'));

            PlatformMessage::create([
                'tenant_id' => $seller->id,
                'sender_type' => PlatformMessage::TYPE_CUSTOMER,
                'sender_name' => $order->buyer_name,
                'sender_email' => $order->buyer_email,
                'recipient_type' => PlatformMessage::TYPE_TENANT,
                'recipient_id' => $seller->id,
                'subject' => "Nouvelle commande Marketplace {$order->order_number}",
                'body' => "Client : {$order->buyer_name} | WhatsApp : {$order->dial_code} {$order->buyer_whatsapp} | Livraison : {$order->delivery_address} | Total : ".number_format($sellerTotal, 0, ',', ' ').' FCFA',
                'is_read' => false,
            ]);

            if (! empty($seller->email)) {
                Mail::to($seller->email)->send(new MarketplaceNewOrderMail(
                    sellerGarageName: $seller->name,
                    order: $order,
                    sellerItems: $sellerItems,
                    sellerTotal: $sellerTotal,
                ));
            }
        }

        session()->forget('marketplace_cart');

        $sellersCount = count($itemsByTenant);
        $msg = $sellersCount > 1
            ? "Commande {$order->order_number} validée avec succès ! Chacun des {$sellersCount} garages vendeurs a reçu sa propre commande par e-mail et dans son espace."
            : "Commande {$order->order_number} validée avec succès ! Le vendeur a été informé et vous contactera très rapidement.";

        return redirect()->route('marketplace.index')
            ->with('success', $msg);
    }

    private function transformProduct(Product $p): array
    {
        $urls = collect($p->images ?? [])->map(function ($path, $idx) use ($p) {
            if (is_string($path) && (str_starts_with($path, '/') || str_starts_with($path, 'http'))) {
                return $path;
            }

            return route('marketplace.product.image', [
                'product' => $p->id,
                'index' => $idx,
            ]);
        })->values()->all();

        return [
            'id' => $p->id,
            'title' => $p->title,
            'slug' => $p->slug,
            'sku' => $p->sku,
            'brand' => $p->brand,
            'vehicle_compatibility' => $p->vehicle_compatibility,
            'condition' => $p->condition,
            'price' => $p->price,
            'stock_quantity' => $p->stock_quantity,
            'country_code' => $p->country_code,
            'country_name' => $p->country?->name ?? $p->country_code,
            'category' => $p->category ? [
                'id' => $p->category->id,
                'name' => $p->category->name,
                'slug' => $p->category->slug,
            ] : null,
            'sub_category' => $p->subCategory ? [
                'id' => $p->subCategory->id,
                'name' => $p->subCategory->name,
                'slug' => $p->subCategory->slug,
            ] : null,
            'seller' => $p->tenant ? [
                'id' => $p->tenant->id,
                'name' => $p->tenant->name,
                'city' => $p->tenant->city,
            ] : null,
            'description' => $p->description,
            'image_urls' => $urls,
            'primary_image' => $urls[0] ?? null,
            'views_count' => $p->views_count,
            'rating_avg' => round((float) ($p->reviews_avg_rating ?? 0), 1),
            'reviews_count' => (int) ($p->reviews_count ?? 0),
        ];
    }
}
