<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    use BelongsToTenant;

    public const STATUS_OPEN = 'ouvert';
    public const STATUS_CLOSED = 'cloture';

    protected $fillable = [
        'tenant_id',
        'label',
        'start_date',
        'end_date',
        'status',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
