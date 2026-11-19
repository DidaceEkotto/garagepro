<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    protected $table = 'marketplace_categories';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'icon',
        'description',
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

    public function subCategories(): HasMany
    {
        return $this->hasMany(SubCategory::class, 'category_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public static function seedDefaults(): void
    {
        $catalog = [
            [
                'name' => 'Moteur & Distribution',
                'icon' => 'bi-gear-wide-connected',
                'description' => 'Courroies, pistons, joints de culasse, injecteurs et turbos.',
                'subs' => ['Courroies & Kits distribution', 'Injecteurs & Pompes', 'Turbocompresseurs', 'Joints moteur'],
            ],
            [
                'name' => 'Freinage',
                'icon' => 'bi-disc',
                'description' => 'Plaquettes, disques, étriers et liquides de frein.',
                'subs' => ['Plaquettes de frein', 'Disques de frein', 'Étriers & Maîtres-cylindres', 'Capteurs ABS'],
            ],
            [
                'name' => 'Suspension & Direction',
                'icon' => 'bi-arrows-expand-vertical',
                'description' => 'Amortisseurs, rotules, triangles et crémaillères.',
                'subs' => ['Amortisseurs', 'Rotules & Biellettes', 'Triangles de suspension', 'Roulements de roue'],
            ],
            [
                'name' => 'Électricité & Éclairage',
                'icon' => 'bi-lightning-charge',
                'description' => 'Batteries, alternateurs, démarreurs et optiques.',
                'subs' => ['Batteries', 'Alternateurs & Démarreurs', 'Phares & Feux', 'Bougies & Bobines'],
            ],
            [
                'name' => 'Filtres & Huiles',
                'icon' => 'bi-droplet',
                'description' => 'Huiles moteur, filtres à huile, air, carburant et habitacle.',
                'subs' => ['Huiles moteur', 'Filtres à huile', 'Filtres à air & habitacle', 'Filtres à carburant'],
            ],
            [
                'name' => 'Transmission & Embrayage',
                'icon' => 'bi-diagram-3',
                'description' => 'Kits d\'embrayage, volants moteur et cardans.',
                'subs' => ['Kits d\'embrayage', 'Cardans & Soufflets', 'Boîtes de vitesses'],
            ],
            [
                'name' => 'Carrosserie & Vitrage',
                'icon' => 'bi-car-front',
                'description' => 'Pare-chocs, rétroviseurs, pare-brise et ailes.',
                'subs' => ['Pare-chocs & Calandres', 'Rétroviseurs', 'Portières & Ailes'],
            ],
            [
                'name' => 'Climatisation & Refroidissement',
                'icon' => 'bi-snow',
                'description' => 'Radiateurs, compresseurs de clim et ventilateurs.',
                'subs' => ['Radiateurs', 'Compresseurs de clim', 'Pompes à eau & Thermostats'],
            ],
        ];

        foreach ($catalog as $catData) {
            $slug = Str::slug($catData['name']);
            $category = static::whereNull('tenant_id')->where('slug', $slug)->first();
            if (! $category) {
                $category = static::create([
                    'tenant_id' => null,
                    'name' => $catData['name'],
                    'slug' => $slug,
                    'icon' => $catData['icon'],
                    'description' => $catData['description'],
                    'is_active' => true,
                ]);
            }

            foreach ($catData['subs'] as $subName) {
                $subSlug = Str::slug($subName);
                $exists = SubCategory::where('category_id', $category->id)
                    ->whereNull('tenant_id')
                    ->where('slug', $subSlug)
                    ->exists();
                if (! $exists) {
                    SubCategory::create([
                        'tenant_id' => null,
                        'category_id' => $category->id,
                        'name' => $subName,
                        'slug' => $subSlug,
                        'is_active' => true,
                    ]);
                }
            }
        }
    }
}
