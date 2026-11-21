<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bulletin de paie mensuel figé d'un employé.
 */
class Payslip extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'brouillon';
    public const STATUS_VALIDATED = 'valide';
    public const STATUS_PAID = 'paye';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'period_month',
        'base_salary',
        'primes_total',
        'commissions_total',
        'overtime_total',
        'late_minutes_total',
        'late_penalties_total',
        'advances_total',
        'net_salary',
        'status',
        'payment_method',
        'paid_at',
        'whatsapp_sent_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'integer',
            'primes_total' => 'integer',
            'commissions_total' => 'integer',
            'overtime_total' => 'integer',
            'late_minutes_total' => 'integer',
            'late_penalties_total' => 'integer',
            'advances_total' => 'integer',
            'net_salary' => 'integer',
            'paid_at' => 'datetime',
            'whatsapp_sent_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
