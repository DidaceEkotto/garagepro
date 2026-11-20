<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Compte client global unique MboaCar partagé en identité entre garages
 * (sans jamais exposer les données financières ou notes privées d'un garage à un autre).
 */
class GlobalCustomer extends Model
{
    protected $fillable = [
        'name',
        'first_name',
        'gender',
        'id_number',
        'phone_dial_code',
        'phone',
        'whatsapp_phone',
        'email',
        'profession',
        'date_of_birth',
        'emergency_contact',
        'description',
        'otp_code',
        'otp_expires_at',
        'last_login_at',
    ];

    protected $hidden = [
        'otp_code',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'otp_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function garageCustomers(): HasMany
    {
        return $this->hasMany(Customer::class, 'global_customer_id');
    }
}
