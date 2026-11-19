<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Utilisateur d'un tenant (gérant, réceptionniste, chef d'atelier, mécanicien, magasinier, comptable).
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, HasRoles, Notifiable;

    protected string $guard_name = 'web';

    protected $attributes = [
        'is_active' => true,
        'failed_login_attempts' => 0,
    ];

    protected $fillable = [
        'tenant_id',
        'default_agency_id',
        'name',
        'email',
        'phone',
        'gender',
        'avatar_path',
        'password',
        'is_active',
        'failed_login_attempts',
        'locked_until',
        'two_factor_secret',
        'two_factor_confirmed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function defaultAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'default_agency_id');
    }

    public function agencies(): BelongsToMany
    {
        return $this->belongsToMany(Agency::class, 'agency_user')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    public function hasAccessToAgency(int $agencyId): bool
    {
        if ($this->hasRole('gerant')) {
            return Agency::where('id', $agencyId)->where('tenant_id', $this->tenant_id)->exists();
        }

        return $this->agencies()->where('agencies.id', $agencyId)->exists();
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
