<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubCategory extends Model
{
    protected $table = 'marketplace_sub_categories';

    protected $fillable = [
        'tenant_id',
        'category_id',
        'name',
        'slug',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeAvailableForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where(function (Builder $q) use ($tenantId) {
            $q->whereNull('tenant_id');
            if ($tenantId !== null) {
                $q->orWhere('tenant_id', $tenantId);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'sub_category_id');
    }
}
