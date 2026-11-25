<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDiagnosticMessage extends Model
{
    use BelongsToTenant;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'user_id',
        'role',
        'content',
        'model',
        'is_off_topic',
        'tokens_used',
    ];

    protected function casts(): array
    {
        return [
            'is_off_topic' => 'boolean',
            'tokens_used' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiDiagnosticConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
