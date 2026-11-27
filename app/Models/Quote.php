<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Devis multi-versions avec niveaux (urgent, recommandé, optionnel) et montants en FCFA.
 */
class Quote extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUS_DRAFT = 'brouillon';
    public const STATUS_SENT = 'envoye';
    public const STATUS_PARTIALLY_ACCEPTED = 'accepte_partiel';
    public const STATUS_ACCEPTED = 'accepte';
    public const STATUS_REFUSED = 'refuse';
    public const STATUS_EXPIRED = 'expire';

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'customer_id',
        'vehicle_id',
        'repair_order_id',
        'parent_quote_id',
        'quote_number',
        'public_token',
        'version',
        'status',
        'sent_at',
        'valid_until',
        'vat_rate',
        'discount_amount',
        'subtotal_ht',
        'vat_amount',
        'total_ttc',
        'deposit_required',
        'client_signature_path',
        'signed_at',
        'signed_ip',
        'signed_by_name',
        'rejection_reason',
        'rejected_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'sent_at' => 'datetime',
            'valid_until' => 'date',
            'vat_rate' => 'decimal:2',
            'discount_amount' => 'integer',
            'subtotal_ht' => 'integer',
            'vat_amount' => 'integer',
            'total_ttc' => 'integer',
            'deposit_required' => 'integer',
            'signed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Quote $quote) {
            if (empty($quote->public_token)) {
                $quote->public_token = Str::random(32);
            }
        });
    }

    public function ensurePublicToken(): string
    {
        if (empty($this->public_token)) {
            $this->update(['public_token' => Str::random(32)]);
        }

        return $this->public_token;
    }

    public function publicUrl(): string
    {
        return route('public.quote.show', ['token' => $this->ensurePublicToken()]);
    }

    public function signatureUrl(): ?string
    {
        return $this->client_signature_path ? asset('storage/'.$this->client_signature_path) : null;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
        return $this->hasMany(QuoteItem::class);
    }

    /**
     * Recalcule les totaux en entiers FCFA à partir des lignes acceptées.
     */
    public function recalculateTotals(): void
    {
        $subtotal = (int) $this->items()
            ->where('is_accepted', true)
            ->sum('total_price');

        $netHt = max(0, $subtotal - (int) $this->discount_amount);
        $vat = (int) round(($netHt * (float) $this->vat_rate) / 100);
        $ttc = $netHt + $vat;

        $this->update([
            'subtotal_ht' => $subtotal,
            'vat_amount' => $vat,
            'total_ttc' => $ttc,
        ]);
    }
}
