<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fiche client du garage (particulier, entreprise ou flotte).
 */
class Customer extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'name',
        'phone',
        'email',
        'address',
        'city',
        'type',
        'company_name',
        'tax_id',
        'credit_limit',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'integer',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
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
