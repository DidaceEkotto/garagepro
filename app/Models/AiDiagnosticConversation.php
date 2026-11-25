<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiDiagnosticConversation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'vehicle_id',
        'title',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiDiagnosticMessage::class, 'conversation_id')->orderBy('id');
    }
}
