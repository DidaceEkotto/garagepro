<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MarketplaceProductSeeder extends Seeder
{
    public function run(): void
    {
        if (Category::count() === 0) {
            Category::seedDefaults();
        }

        $tenant = Tenant::first();
        if (! $tenant) {
            $tenant = Tenant::create([
                'name' => 'GaragePro Auto Pièces',
                'slug' => 'garagepro-auto-pieces',
                'email' => 'pieces@garagepro.app',
                'phone' => '+237 699000111',
                'country' => 'CM',
                'city' => 'Douala',
                'status' => Tenant::STATUS_ACTIVE,
            ]);
        }

        $items = [
            [
                'cat' => 'freinage',
                'sub' => 'plaquettes-de-frein',
                'title' => 'Jeu de 4 plaquettes de frein avant Bosch',
                'brand' => 'Bosch',
                'sku' => 'BSH-PLQ-401',
                'compat' => 'Toyota Corolla, Yaris, Avensis',
                'condition' => 'neuf',
                'price' => 28500,
                'stock' => 14,
                'country' => 'CM',
                'desc' => 'Plaquettes de frein avant haute performance Bosch avec témoin d\'usure acoustique. Freinage silencieux et durable.',
            ],
            [
                'cat' => 'freinage',
                'sub' => 'disques-de-frein',
                'title' => 'Paire de disques de frein ventilés Brembo',
                'brand' => 'Brembo',
                'sku' => 'BRM-DSK-210',
                'compat' => 'Peugeot 308, 3008, Citroën C4',
                'condition' => 'neuf',
                'price' => 54000,
                'stock' => 8,
                'country' => 'SN',
                'desc' => 'Disques ventilés traités anti-corrosion pour une dissipation thermique optimale.',
            ],
            [
                'cat' => 'moteur-distribution',
                'sub' => 'courroies-kits-distribution',
                'title' => 'Kit de distribution complet + pompe à eau Gates',
                'brand' => 'Gates',
                'sku' => 'GTS-KIT-902',
                'compat' => 'Renault Duster, Logan, Clio 1.5 dCi',
                'condition' => 'neuf',
                'price' => 78000,
                'stock' => 6,
                'country' => 'CI',
                'desc' => 'Kit complet incluant courroie crantée, galet tendeur, galet enrouleur et pompe à eau renforcée.',
            ],
            [
                'cat' => 'moteur-distribution',
                'sub' => 'turbocompresseurs',
                'title' => 'Turbocompresseur Garrett reconditionné garanti',
                'brand' => 'Garrett',
                'sku' => 'GRT-TRB-550',
                'compat' => 'Toyota Hilux 2.5 D-4D, Fortuner',
                'condition' => 'occasion',
                'price' => 245000,
                'stock' => 3,
                'country' => 'CM',
                'desc' => 'Turbo contrôlé au banc d\'essai avec géométrie variable calibrée et joint d\'étanchéité neuf.',
            ],
            [
                'cat' => 'suspension-direction',
                'sub' => 'amortisseurs',
                'title' => 'Paire d\'amortisseurs avant à gaz Monroe',
                'brand' => 'Monroe',
                'sku' => 'MNR-AMR-312',
                'compat' => 'Hyundai Tucson, Kia Sportage',
                'condition' => 'neuf',
                'price' => 92000,
                'stock' => 10,
                'country' => 'GA',
                'desc' => 'Amortisseurs bitube à gaz conçus pour absorber efficacement les routes difficiles et pistes.',
            ],
            [
                'cat' => 'electricite-eclairage',
                'sub' => 'batteries',
                'title' => 'Batterie Varta Blue Dynamic 12V 74Ah 680A',
                'brand' => 'Varta',
                'sku' => 'VRT-BAT-074',
                'compat' => 'Berlines & SUV Diesel / Essence',
                'condition' => 'neuf',
                'price' => 68000,
                'stock' => 20,
                'country' => 'CM',
                'desc' => 'Démarrage fiable par fortes chaleurs, technologie PowerFrame sans entretien.',
            ],
            [
                'cat' => 'filtres-huiles',
                'sub' => 'huiles-moteur',
                'title' => 'Bidon Huile Moteur Total Quartz 9000 5W-40 (5L)',
                'brand' => 'TotalEnergies',
                'sku' => 'TOT-HUI-540',
                'compat' => 'Moteurs essence et diesel multisoupapes',
                'condition' => 'neuf',
                'price' => 24000,
                'stock' => 35,
                'country' => 'SN',
                'desc' => 'Huile 100% synthétique offrant une protection maximale contre l\'usure et l\'encrassement.',
            ],
            [
                'cat' => 'transmission-embrayage',
                'sub' => 'kits-dembrayage',
                'title' => 'Kit d\'embrayage 3 pièces Valeo (Mécanisme + Disque + Butée)',
                'brand' => 'Valeo',
                'sku' => 'VLO-EMB-808',
                'compat' => 'Toyota RAV4, Nissan Qashqai',
                'condition' => 'neuf',
                'price' => 115000,
                'stock' => 5,
                'country' => 'CI',
                'desc' => 'Kit embrayage complet qualité première monte pour un passage de vitesses souple et précis.',
            ],
            [
                'cat' => 'climatisation-refroidissement',
                'sub' => 'compresseurs-de-clim',
                'title' => 'Compresseur de climatisation Sanden 12V',
                'brand' => 'Sanden',
                'sku' => 'SND-CLM-701',
                'compat' => 'Mercedes Classe C W204, Classe E',
                'condition' => 'adaptable',
                'price' => 145000,
                'stock' => 4,
                'country' => 'MA',
                'desc' => 'Compresseur neuf pré-rempli en huile PAG, poulie 6 gorges incluse.',
            ],
            [
                'cat' => 'carrosserie-vitrage',
                'sub' => 'retroviseurs',
                'title' => 'Rétroviseur extérieur électrique rabattable droit',
                'brand' => 'TYC',
                'sku' => 'TYC-RET-119',
                'compat' => 'Toyota Land Cruiser Prado',
                'condition' => 'adaptable',
                'price' => 49000,
                'stock' => 7,
                'country' => 'CM',
                'desc' => 'Rétroviseur complet avec rappel de clignotant LED intégré, prêt à peindre.',
            ],
        ];

        foreach ($items as $item) {
            $category = Category::where('slug', $item['cat'])->first();
            if (! $category) {
                continue;
            }
            $subCategory = SubCategory::where('category_id', $category->id)
                ->where('slug', $item['sub'])
                ->first();

            Product::withoutTenantScope()->firstOrCreate(
                ['sku' => $item['sku']],
                [
                    'tenant_id' => $tenant->id,
                    'category_id' => $category->id,
                    'sub_category_id' => $subCategory?->id,
                    'title' => $item['title'],
                    'slug' => Str::slug($item['title']).'-'.Str::lower(Str::random(4)),
                    'brand' => $item['brand'],
                    'vehicle_compatibility' => $item['compat'],
                    'condition' => $item['condition'],
                    'price' => $item['price'],
                    'stock_quantity' => $item['stock'],
                    'country_code' => $item['country'],
                    'description' => $item['desc'],
                    'images' => ['/images/hero-garage.jpg', '/images/auth-garage.jpg'],
                    'is_published' => true,
                ]
            );
        }
    }
}
