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
        'attachments',
        'audio_path',
        'audio_mime',
        'audio_duration',
        'model',
        'is_off_topic',
        'tokens_used',
        'client_summary',
        'client_whatsapp_sent_at',
        'is_resolved',
        'feedback_note',
        'feedback_at',
        'is_approved_for_knowledge',
        'matched_from_message_id',
        'reuse_count',
    ];

    protected $appends = [
        'photo_urls',
        'audio_url',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'audio_duration' => 'integer',
            'is_off_topic' => 'boolean',
            'tokens_used' => 'integer',
            'client_whatsapp_sent_at' => 'datetime',
            'is_resolved' => 'boolean',
            'feedback_at' => 'datetime',
            'is_approved_for_knowledge' => 'boolean',
            'matched_from_message_id' => 'integer',
            'reuse_count' => 'integer',
        ];
    }

    public function getPhotoUrlsAttribute(): array
    {
        $items = is_array($this->attachments) ? $this->attachments : [];

        return collect($items)
            ->filter(fn ($a) => is_array($a) && ! empty($a['path']))
            ->map(fn ($a) => asset('storage/'.ltrim((string) $a['path'], '/')))
            ->values()
            ->all();
    }

    public function getAudioUrlAttribute(): ?string
    {
        return $this->audio_path ? asset('storage/'.ltrim((string) $this->audio_path, '/')) : null;
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
