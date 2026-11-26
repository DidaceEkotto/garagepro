<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Véhicule rattaché à un client et identifié par son immatriculation.
 */
class Vehicle extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'client_uuid',
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
        'technical_inspection_expires_at',
        'insurance_expires_at',
        'maintenance_reminder_sent_at',
        'notes',
        'public_token',
    ];

    protected static function booted(): void
    {
        static::creating(function (Vehicle $vehicle) {
            if (empty($vehicle->public_token)) {
                $vehicle->public_token = Str::random(24);
            }
        });
    }

    public function ensurePublicToken(): string
    {
        if (empty($this->public_token)) {
            $this->update(['public_token' => Str::random(24)]);
        }

        return $this->public_token;
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'mileage' => 'integer',
            'next_service_mileage' => 'integer',
            'first_registration_date' => 'date',
            'next_service_date' => 'date',
            'technical_inspection_expires_at' => 'date',
            'insurance_expires_at' => 'date',
            'maintenance_reminder_sent_at' => 'datetime',
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
