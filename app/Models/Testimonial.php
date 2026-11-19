<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Témoignage client géré depuis le back-office Super-Admin et affiché sur la landing page.
 */
class Testimonial extends Model
{
    protected $fillable = [
        'author_name',
        'author_role',
        'garage_name',
        'city',
        'quote',
        'rating',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public static function seedDefaults(): void
    {
        if (static::count() > 0) {
            return;
        }

        $defaults = [
            [
                'author_name' => 'Paul Mbarga',
                'author_role' => 'Gérant',
                'garage_name' => 'Garage Excellence Auto',
                'city' => 'Douala',
                'quote' => 'Nous utilisons GaragePro depuis plus de 6 mois pour nos deux ateliers. Cela nous a apporté une belle visibilité sur nos ordres de réparation, nos stocks de pièces et nos encaissements Mobile Money.',
                'rating' => 5,
                'is_published' => true,
            ],
            [
                'author_name' => 'Aminata Diallo',
                'author_role' => 'Directrice Technique',
                'garage_name' => 'Dakar Motors Service',
                'city' => 'Dakar',
                'quote' => 'Le pointage automatique et le calcul de la paie avec retenues nous font gagner des heures chaque fin de mois. Tout est clair, professionnel et adapté à nos réalités.',
                'rating' => 5,
                'is_published' => true,
            ],
            [
                'author_name' => 'Kouassi Jean',
                'author_role' => 'Propriétaire',
                'garage_name' => 'Atelier Abidjan Pro',
                'city' => 'Abidjan',
                'quote' => 'Les devis envoyés en un clic et la facturation conforme SYSCOHADA ont immédiatement rassuré nos clients entreprises. Notre chiffre d\'affaires est suivi au franc près.',
                'rating' => 5,
                'is_published' => true,
            ],
            [
                'author_name' => 'Karine Tchatchouang',
                'author_role' => 'Responsable Administratif',
                'garage_name' => 'Yaoundé Méca Express',
                'city' => 'Yaoundé',
                'quote' => 'Avant GaragePro, on perdait des pièces et des factures papier. Aujourd\'hui chaque réception a ses photos, son diagnostic et son suivi jusqu\'à la sortie du véhicule.',
                'rating' => 5,
                'is_published' => true,
            ],
        ];

        foreach ($defaults as $item) {
            static::create($item);
        }
    }
}
