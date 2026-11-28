<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WhatsappBotLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'sender_phone',
        'incoming_message',
        'bot_reply',
        'rdv_link_url',
    ];
}
