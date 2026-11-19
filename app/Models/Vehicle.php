<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Véhicule rattaché à un client et identifié par son immatriculation.
 */
class Vehicle extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'license_plate',
        'brand',
        'model',
        'year',
        'vin',
        'color',
        'fuel_type',
        'transmission',
        'mileage',
        'first_registration_date',
        'next_service_date',
        'next_service_mileage',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'mileage' => 'integer',
            'next_service_mileage' => 'integer',
            'first_registration_date' => 'date',
            'next_service_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(VehicleReception::class);
    }
}
