<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MarketplaceBanner extends Model
{
    protected $fillable = [
        'title',
        'subtitle',
        'placement',
        'image_path',
        'gradient_from',
        'gradient_to',
        'button_text',
        'button_url',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function resolvedImageUrl(): ?string
    {
        if (empty($this->image_path)) {
            return null;
        }
        if (str_starts_with($this->image_path, '/') || str_starts_with($this->image_path, 'http')) {
            return $this->image_path;
        }

        return route('marketplace.banner.image', ['banner' => $this->id]);
    }

    public function isExternalUrl(): bool
    {
        if (empty($this->button_url)) {
            return false;
        }

        return str_starts_with($this->button_url, 'http://') || str_starts_with($this->button_url, 'https://');
    }
}
