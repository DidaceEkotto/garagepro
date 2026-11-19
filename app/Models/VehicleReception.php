<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fiche de réception et état des lieux d'entrée d'un véhicule.
 */
class VehicleReception extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'customer_id',
        'vehicle_id',
        'appointment_id',
        'received_by',
        'courtesy_vehicle_id',
        'courtesy_deposit_amount',
        'courtesy_returned_at',
        'visit_reason',
        'mileage_in',
        'fuel_level',
        'dashboard_warnings',
        'belongings_left',
        'damage_markers',
        'client_signature_path',
        'photos',
        'received_at',
        'estimated_exit_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'courtesy_deposit_amount' => 'integer',
            'courtesy_returned_at' => 'datetime',
            'mileage_in' => 'integer',
            'fuel_level' => 'integer',
            'dashboard_warnings' => 'array',
            'damage_markers' => 'array',
            'photos' => 'array',
            'received_at' => 'datetime',
            'estimated_exit_at' => 'datetime',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function courtesyVehicle(): BelongsTo
    {
        return $this->belongsTo(CourtesyVehicle::class);
    }
}
