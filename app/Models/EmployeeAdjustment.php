<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avance sur salaire (acompte) ou prime accordée à un employé.
 */
class EmployeeAdjustment extends Model
{
    use BelongsToTenant;

    public const TYPE_ADVANCE = 'avance';
    public const TYPE_BONUS = 'prime';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'type',
        'amount',
        'label',
        'effective_date',
        'period_month',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'effective_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
