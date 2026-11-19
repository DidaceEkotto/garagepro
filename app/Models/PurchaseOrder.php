<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'brouillon';
    public const STATUS_SENT = 'envoye';
    public const STATUS_PARTIAL = 'recu_partiel';
    public const STATUS_RECEIVED = 'recu';
    public const STATUS_CANCELLED = 'annule';

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'supplier_id',
        'po_number',
        'status',
        'ordered_at',
        'expected_at',
        'received_at',
        'total_amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'expected_at' => 'date',
            'received_at' => 'datetime',
            'total_amount' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
