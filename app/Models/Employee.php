<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'user_id',
        'full_name',
        'role_title',
        'phone',
        'gender',
        'marital_status',
        'birth_date',
        'birth_place',
        'photo_path',
        'base_salary',
        'commission_rate',
        'is_active',
        'hired_at',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'integer',
            'commission_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'hired_at' => 'date',
            'birth_date' => 'date',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(EmployeeAttendance::class);
    }
}
