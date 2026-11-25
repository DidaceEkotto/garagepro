<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Partenaire affiché dans le bandeau défilant de la landing page.
 */
class Partner extends Model
{
    protected $fillable = [
        'name',
        'logo_path',
        'website_url',
        'sort_order',
        'is_active',
    ];

    protected $appends = ['logo_url'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function getLogoUrlAttribute(): string
    {
        if (str_starts_with($this->logo_path, 'http://') || str_starts_with($this->logo_path, 'https://') || str_starts_with($this->logo_path, '/')) {
            return $this->logo_path;
        }

        return asset('storage/'.$this->logo_path);
    }
}
