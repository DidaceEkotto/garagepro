<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Country;
use App\Models\MarketplaceOrderItem;
use App\Models\Product;
use App\Models\SubCategory;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
            'condition' => ['required', Rule::in(['neuf', 'occasion', 'adaptable'])],
            'price' => ['required', 'integer', 'min:100'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'country_code' => ['required', 'string', 'size:2'],
            'description' => ['nullable', 'string', 'max:3000'],
            'images' => ['nullable', 'array'],
            'images.*' => ['file', 'image', 'max:8192'],
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
        foreach ($request->file('images', []) as $file) {
            if ($file instanceof UploadedFile) {
                $imagePaths[] = Storage::disk('local')->putFile("marketplace/{$tenantId}", $file);
            }
        }

        $cleanDescription = ! empty($data['description'])
            ? strip_tags($data['description'], '<p><br><b><strong><i><em><u><ul><ol><li><h3><h4>')
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
        $tenantId = $tenantContext->id();

        $orderItems = MarketplaceOrderItem::with(['order', 'product'])
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->paginate(25);

        MarketplaceOrderItem::where('tenant_id', $tenantId)
            ->where('is_seen_by_seller', false)
            ->update(['is_seen_by_seller' => true]);

        return Inertia::render('Tenant/Marketplace/Orders', [
            'orderItems' => $orderItems,
        ]);
    }

    public function updateOrderStatus(Request $request, MarketplaceOrderItem $orderItem, TenantContext $tenantContext): RedirectResponse
    {
        if ((int) $orderItem->tenant_id !== (int) $tenantContext->id()) {
            abort(403);
        }

        $data = $request->validate([
            'seller_status' => ['required', Rule::in(['nouvelle', 'confirmee', 'expediee', 'livree', 'annulee'])],
        ]);

        $orderItem->update([
            'seller_status' => $data['seller_status'],
            'is_seen_by_seller' => true,
        ]);

        return back()->with('success', 'Statut de la commande mis à jour.');
    }
}
