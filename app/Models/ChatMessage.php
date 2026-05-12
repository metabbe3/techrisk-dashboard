<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class ChatMessage extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'model',
        'persona_key',
        'persona_name',
        'persona_icon',
        'persona_color',
        'tokens_used',
        'prompt_tokens',
        'completion_tokens',
        'feedback',
        'feedback_comment',
        'web_search_used',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'web_search_used' => 'boolean',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }
}
