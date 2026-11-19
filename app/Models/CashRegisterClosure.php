<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashRegisterClosure extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'closed_by',
        'closure_date',
        'cash_total',
        'mobile_money_total',
        'card_bank_total',
        'total_amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'closure_date' => 'date',
            'cash_total' => 'integer',
            'mobile_money_total' => 'integer',
            'card_bank_total' => 'integer',
            'total_amount' => 'integer',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
