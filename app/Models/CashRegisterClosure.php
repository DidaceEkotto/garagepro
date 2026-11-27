<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashRegisterClosure extends Model
{
    use BelongsToTenant;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'opened_by',
        'closed_by',
        'closure_date',
        'status',
        'opened_at',
        'closed_at',
        'opening_float',
        'cash_total',
        'mobile_money_total',
        'card_bank_total',
        'virement_total',
        'expenses_cash_total',
        'expected_cash',
        'counted_cash',
        'cash_discrepancy',
        'total_amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'closure_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float' => 'integer',
            'cash_total' => 'integer',
            'mobile_money_total' => 'integer',
            'card_bank_total' => 'integer',
            'virement_total' => 'integer',
            'expenses_cash_total' => 'integer',
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'cash_discrepancy' => 'integer',
            'total_amount' => 'integer',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
