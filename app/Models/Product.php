<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToTenant;

    protected $table = 'marketplace_products';

    protected $fillable = [
        'tenant_id',
        'category_id',
        'sub_category_id',
        'title',
        'slug',
        'sku',
        'brand',
        'vehicle_compatibility',
        'vehicle_brand',
        'vehicle_model',
        'vehicle_year_from',
        'vehicle_year_to',
        'condition',
        'price',
        'stock_quantity',
        'country_code',
        'description',
        'images',
        'is_published',
        'views_count',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'stock_quantity' => 'integer',
            'images' => 'array',
            'is_published' => 'boolean',
            'views_count' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(SubCategory::class, 'sub_category_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(MarketplaceOrderItem::class, 'marketplace_product_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->slug)) {
                $base = \Illuminate\Support\Str::slug($product->title ?: 'produit');
                $product->slug = "{$base}-".strtolower(\Illuminate\Support\Str::random(6));
            }
        });
    }
}
