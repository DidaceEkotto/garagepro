<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Véhicule de courtoisie prêté au client pendant l'immobilisation.
 */
class CourtesyVehicle extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUS_AVAILABLE = 'disponible';
    public const STATUS_LOANED = 'prete';
    public const STATUS_MAINTENANCE = 'maintenance';

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'name',
        'license_plate',
        'status',
        'current_mileage',
        'deposit_amount',
    ];

    protected function casts(): array
    {
        return [
            'current_mileage' => 'integer',
            'deposit_amount' => 'integer',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
