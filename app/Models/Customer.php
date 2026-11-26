<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\GlobalCustomerService;
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
        'client_uuid',
        'global_customer_id',
        'agency_id',
        'name',
        'first_name',
        'gender',
        'id_number',
        'phone_dial_code',
        'phone',
        'whatsapp_phone',
        'photo_path',
        'email',
        'address',
        'city',
        'type',
        'company_name',
        'tax_id',
        'credit_limit',
        'fleet_manager_name',
        'fleet_notes',
        'notes',
        'description',
        'profession',
        'date_of_birth',
        'emergency_contact',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'integer',
            'date_of_birth' => 'date',
        ];
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? asset('storage/'.$this->photo_path) : null;
    }

    protected static function booted(): void
    {
        static::created(function (Customer $customer) {
            app(GlobalCustomerService::class)->syncFromLocalCustomer($customer);
        });
    }

    public function globalCustomer(): BelongsTo
    {
        return $this->belongsTo(GlobalCustomer::class, 'global_customer_id');
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

    public function repairOrders(): HasMany
    {
        return $this->hasMany(RepairOrder::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
