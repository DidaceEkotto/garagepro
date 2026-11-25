<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Administrateur de la plateforme SaaS (guard dédié 'admin').
 */
class Admin extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_SUPPORT = 'support';
    public const ROLE_MARKETING = 'marketing';
    public const ROLE_MODERATOR = 'moderator';

    public const ROLES = [
        self::ROLE_SUPER_ADMIN => 'Super-Admin (Accès complet)',
        self::ROLE_SUPPORT => 'Support (Garages, Messagerie, Affiliation)',
        self::ROLE_MARKETING => 'Marketing (Marketplace, Témoignages, Pays)',
        self::ROLE_MODERATOR => 'Modérateur (Garages, Affiliation, Sécurité)',
    ];

    protected $attributes = [
        'is_active' => true,
        'failed_login_attempts' => 0,
    ];

    protected $fillable = [
        'name',
        'email',
        'role',
        'avatar_path',
        'gender',
        'job_title',
        'bio',
        'phone',
        'birth_date',
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
            'password' => 'hashed',
            'is_active' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'birth_date' => 'date',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? asset('storage/'.$this->avatar_path) : null;
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role ?? self::ROLE_SUPER_ADMIN] ?? 'Administrateur';
    }

    public function allowedSections(): array
    {
        return match ($this->role ?? self::ROLE_SUPER_ADMIN) {
            self::ROLE_SUPPORT => ['overview', 'tenants', 'ai_diagnostics', 'messages', 'affiliation', 'profile'],
            self::ROLE_MARKETING => ['overview', 'categories', 'banners', 'testimonials', 'partners', 'countries', 'terms', 'privacy', 'profile'],
            self::ROLE_MODERATOR => ['overview', 'tenants', 'ai_diagnostics', 'affiliation', 'security', 'profile'],
            default => [
                'overview', 'tenants', 'ai_diagnostics', 'messages', 'affiliation',
                'categories', 'banners', 'testimonials', 'partners', 'countries',
                'terms', 'privacy',
                'security', 'admins', 'settings', 'profile',
            ],
        };
    }

    public function canAccessSection(string $section): bool
    {
        return in_array($section, $this->allowedSections(), true);
    }
}
