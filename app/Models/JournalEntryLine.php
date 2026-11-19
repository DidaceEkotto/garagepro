<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'journal_entry_id',
        'account_id',
        'auxiliary_label',
        'debit',
        'credit',
        'lettering_code',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'integer',
            'credit' => 'integer',
            'reconciled_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'account_id');
    }
}
