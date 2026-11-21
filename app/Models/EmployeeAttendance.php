<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAttendance extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'employee_id',
        'work_date',
        'status',
        'checked_in_at',
        'checked_out_at',
        'late_minutes',
        'late_penalty',
        'overtime_minutes',
        'overtime_bonus',
        'commission_earned',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'late_minutes' => 'integer',
            'late_penalty' => 'integer',
            'overtime_minutes' => 'integer',
            'overtime_bonus' => 'integer',
            'commission_earned' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
