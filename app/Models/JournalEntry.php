<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use BelongsToTenant;

    public const STATUS_VALIDATED = 'validee';
    public const STATUS_REVERSED = 'contrepassee';

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'fiscal_year_id',
        'journal_id',
        'entry_number',
        'entry_date',
        'label',
        'source_type',
        'source_id',
        'reversed_entry_id',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
        ];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'journal_id');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }
}
