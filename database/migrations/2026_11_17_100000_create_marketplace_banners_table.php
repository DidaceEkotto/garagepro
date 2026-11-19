<?php

use App\Models\MarketplaceBanner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_banners', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->string('subtitle', 255)->nullable();
            $table->string('placement', 30)->default('row_1'); // row_1 | row_2
            $table->string('image_path', 255)->nullable();
            $table->string('gradient_from', 30)->default('#0f172a');
            $table->string('gradient_to', 30)->default('#2563eb');
            $table->string('button_text', 80)->nullable();
            $table->string('button_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        MarketplaceBanner::create([
            'title' => 'Pièces d\'origine garanties pour votre atelier',
            'subtitle' => 'Commandez en ligne et profitez d\'une livraison rapide partout en Afrique.',
            'placement' => 'row_1',
            'image_path' => '/images/hero-garage.jpg',
            'gradient_from' => '#0f172a',
            'gradient_to' => '#2563eb',
            'button_text' => 'Voir les bons plans',
            'button_url' => '/marketplace/bons-plans',
            'is_active' => true,
        ]);

        MarketplaceBanner::create([
            'title' => 'Vous êtes gérant de garage ? Vendez vos pièces ici',
            'subtitle' => 'Publiez votre stock sur GaragePro Marketplace et recevez des commandes instantanées.',
            'placement' => 'row_2',
            'image_path' => '/images/auth-garage.jpg',
            'gradient_from' => '#1e1b4b',
            'gradient_to' => '#059669',
            'button_text' => 'Créer mon espace garage',
            'button_url' => '/inscription',
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_banners');
    }
};
