<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Pays disponibles lors de l'inscription avec leur indicatif téléphonique.
 */
class Country extends Model
{
    protected $fillable = [
        'code',
        'name',
        'dial_code',
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

    /**
     * Retourne les 54 pays africains avec leurs indicatifs téléphoniques.
     *
     * @return array<int, array{code: string, name: string, dial_code: string}>
     */
    public static function defaultAfricanCountries(): array
    {
        return [
            ['code' => 'ZA', 'name' => 'Afrique du Sud', 'dial_code' => '+27'],
            ['code' => 'DZ', 'name' => 'Algérie', 'dial_code' => '+213'],
            ['code' => 'AO', 'name' => 'Angola', 'dial_code' => '+244'],
            ['code' => 'BJ', 'name' => 'Bénin', 'dial_code' => '+229'],
            ['code' => 'BW', 'name' => 'Botswana', 'dial_code' => '+267'],
            ['code' => 'BF', 'name' => 'Burkina Faso', 'dial_code' => '+226'],
            ['code' => 'BI', 'name' => 'Burundi', 'dial_code' => '+257'],
            ['code' => 'CM', 'name' => 'Cameroun', 'dial_code' => '+237'],
            ['code' => 'CV', 'name' => 'Cap-Vert', 'dial_code' => '+238'],
            ['code' => 'CF', 'name' => 'République centrafricaine', 'dial_code' => '+236'],
            ['code' => 'KM', 'name' => 'Comores', 'dial_code' => '+269'],
            ['code' => 'CG', 'name' => 'Congo (Brazzaville)', 'dial_code' => '+242'],
            ['code' => 'CD', 'name' => 'République démocratique du Congo', 'dial_code' => '+243'],
            ['code' => 'CI', 'name' => 'Côte d\'Ivoire', 'dial_code' => '+225'],
            ['code' => 'DJ', 'name' => 'Djibouti', 'dial_code' => '+253'],
            ['code' => 'EG', 'name' => 'Égypte', 'dial_code' => '+20'],
            ['code' => 'ER', 'name' => 'Érythrée', 'dial_code' => '+291'],
            ['code' => 'SZ', 'name' => 'Eswatini', 'dial_code' => '+268'],
            ['code' => 'ET', 'name' => 'Éthiopie', 'dial_code' => '+251'],
            ['code' => 'GA', 'name' => 'Gabon', 'dial_code' => '+241'],
            ['code' => 'GM', 'name' => 'Gambie', 'dial_code' => '+220'],
            ['code' => 'GH', 'name' => 'Ghana', 'dial_code' => '+233'],
            ['code' => 'GN', 'name' => 'Guinée', 'dial_code' => '+224'],
            ['code' => 'GW', 'name' => 'Guinée-Bissau', 'dial_code' => '+245'],
            ['code' => 'GQ', 'name' => 'Guinée équatoriale', 'dial_code' => '+240'],
            ['code' => 'KE', 'name' => 'Kenya', 'dial_code' => '+254'],
            ['code' => 'LS', 'name' => 'Lesotho', 'dial_code' => '+266'],
            ['code' => 'LR', 'name' => 'Libéria', 'dial_code' => '+231'],
            ['code' => 'LY', 'name' => 'Libye', 'dial_code' => '+218'],
            ['code' => 'MG', 'name' => 'Madagascar', 'dial_code' => '+261'],
            ['code' => 'MW', 'name' => 'Malawi', 'dial_code' => '+265'],
            ['code' => 'ML', 'name' => 'Mali', 'dial_code' => '+223'],
            ['code' => 'MA', 'name' => 'Maroc', 'dial_code' => '+212'],
            ['code' => 'MU', 'name' => 'Maurice', 'dial_code' => '+230'],
            ['code' => 'MR', 'name' => 'Mauritanie', 'dial_code' => '+222'],
            ['code' => 'MZ', 'name' => 'Mozambique', 'dial_code' => '+258'],
            ['code' => 'NA', 'name' => 'Namibie', 'dial_code' => '+264'],
            ['code' => 'NE', 'name' => 'Niger', 'dial_code' => '+227'],
            ['code' => 'NG', 'name' => 'Nigéria', 'dial_code' => '+234'],
            ['code' => 'UG', 'name' => 'Ouganda', 'dial_code' => '+256'],
            ['code' => 'RW', 'name' => 'Rwanda', 'dial_code' => '+250'],
            ['code' => 'ST', 'name' => 'Sao Tomé-et-Principe', 'dial_code' => '+239'],
            ['code' => 'SN', 'name' => 'Sénégal', 'dial_code' => '+221'],
            ['code' => 'SC', 'name' => 'Seychelles', 'dial_code' => '+248'],
            ['code' => 'SL', 'name' => 'Sierra Leone', 'dial_code' => '+232'],
            ['code' => 'SO', 'name' => 'Somalie', 'dial_code' => '+252'],
            ['code' => 'SD', 'name' => 'Soudan', 'dial_code' => '+249'],
            ['code' => 'SS', 'name' => 'Soudan du Sud', 'dial_code' => '+211'],
            ['code' => 'TZ', 'name' => 'Tanzanie', 'dial_code' => '+255'],
            ['code' => 'TD', 'name' => 'Tchad', 'dial_code' => '+235'],
            ['code' => 'TG', 'name' => 'Togo', 'dial_code' => '+228'],
            ['code' => 'TN', 'name' => 'Tunisie', 'dial_code' => '+216'],
            ['code' => 'ZM', 'name' => 'Zambie', 'dial_code' => '+260'],
            ['code' => 'ZW', 'name' => 'Zimbabwe', 'dial_code' => '+263'],
        ];
    }

    public static function seedDefaults(): void
    {
        foreach (static::defaultAfricanCountries() as $row) {
            static::updateOrCreate(
                ['code' => strtoupper($row['code'])],
                [
                    'name' => $row['name'],
                    'dial_code' => $row['dial_code'],
                    'is_active' => true,
                ]
            );
        }
    }
}
