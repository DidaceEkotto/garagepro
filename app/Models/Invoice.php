<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Facture client, proforma ou avoir avec montants en entiers FCFA.
 */
class Invoice extends Model
{
    use BelongsToTenant, HasFactory;

    public const TYPE_INVOICE = 'facture';
    public const TYPE_PROFORMA = 'proforma';
    public const TYPE_CREDIT_NOTE = 'avoir';

    public const STATUS_ISSUED = 'emise';
    public const STATUS_PARTIAL = 'partiellement_payee';
    public const STATUS_PAID = 'payee';
    public const STATUS_CANCELLED = 'annulee';

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'customer_id',
        'vehicle_id',
        'repair_order_id',
        'parent_invoice_id',
        'invoice_number',
        'type',
        'status',
        'is_deferred_monthly',
        'vat_rate',
        'subtotal_ht',
        'discount_amount',
        'vat_amount',
        'deposit_deducted',
        'total_ttc',
        'paid_amount',
        'issued_at',
        'due_at',
        'overdue_reminder_stage',
        'last_reminder_sent_at',
        'installment_plan',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_deferred_monthly' => 'boolean',
            'vat_rate' => 'decimal:2',
            'subtotal_ht' => 'integer',
            'discount_amount' => 'integer',
            'vat_amount' => 'integer',
            'deposit_deducted' => 'integer',
            'total_ttc' => 'integer',
            'paid_amount' => 'integer',
            'issued_at' => 'datetime',
            'due_at' => 'date',
            'overdue_reminder_stage' => 'integer',
            'last_reminder_sent_at' => 'datetime',
            'installment_plan' => 'array',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function repairOrder(): BelongsTo
    {
        return $this->belongsTo(RepairOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function balanceDue(): int
    {
        return max(0, (int) $this->total_ttc - (int) $this->deposit_deducted - (int) $this->paid_amount);
    }

    public function isSettled(): bool
    {
        return $this->balanceDue() === 0 || $this->status === self::STATUS_PAID;
    }
}
