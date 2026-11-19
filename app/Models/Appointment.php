<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rendez-vous planifié ou passage en file d'attente sans rendez-vous.
 */
class Appointment extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUS_PENDING = 'en_attente';
    public const STATUS_CONFIRMED = 'confirme';
    public const STATUS_WALK_IN_QUEUE = 'en_file';
    public const STATUS_RECEIVED = 'recu';
    public const STATUS_RESCHEDULED = 'reporte';
    public const STATUS_CANCELLED = 'annule';

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'customer_id',
        'vehicle_id',
        'assigned_mechanic_id',
        'bay_name',
        'channel',
        'scheduled_at',
        'duration_minutes',
        'status',
        'reason',
        'notes',
        'reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'reminder_sent_at' => 'datetime',
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

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_mechanic_id');
    }
}
