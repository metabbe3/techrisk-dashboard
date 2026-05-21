<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class ChatConversation extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'user_id',
        'title',
        'model',
        'folder',
        'tags',
        'summary',
        'memory_archived_at',
        'pinned_at',
    ];

    protected $casts = [
        'memory_archived_at' => 'datetime',
        'pinned_at' => 'datetime',
        'tags' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('created_at');
    }

    public function latestMessage()
    {
        return $this->hasOne(ChatMessage::class, 'conversation_id')->latestOfMany();
    }

    public function scopeForUser($query, ?int $userId = null)
    {
        return $query->where('user_id', $userId ?? auth()->id());
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('pinned_at')->orderBy('updated_at', 'desc');
    }

    public function scopePinned($query)
    {
        return $query->whereNotNull('pinned_at');
    }

    public function scopeInFolder($query, string $folder)
    {
        return $query->where('folder', $folder);
    }

    public function scopeWithTag($query, string $tag)
    {
        return $query->whereJsonContains('tags', $tag);
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    public static function getFolders(): array
    {
        return static::where('user_id', auth()->id())
            ->whereNotNull('folder')
            ->groupBy('folder')
            ->orderBy('folder')
            ->pluck('folder')
            ->toArray();
    }
}
