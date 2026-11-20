<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationFormRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'email',
        'token_hash',
        'emailed_at',
        'last_opened_at',
        'revoked_at',
        'last_reminded_at',
        'reminder_count',
    ];

    protected $casts = [
        'emailed_at' => 'datetime',
        'last_opened_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_reminded_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(InvestigationFormRequest::class, 'request_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** The full validity of this person's link: own state + the parent request's. */
    public function linkIsValid(): bool
    {
        return ! $this->isRevoked() && $this->request->linkIsValid();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The plain link token for this recipient. Derived (HMAC of
     * request_id|email, keyed by APP_KEY) rather than random + stored — only
     * the sha256 hash is persisted, so every send site can re-derive the
     * SAME link forever. Nothing rotates: the link lives until
     * submit/revoke/expiry.
     */
    public function linkToken(): string
    {
        $derived = self::tokenFor($this->request_id, $this->email);

        // Lazy re-key of pre-de-rotation rows (hashed from a random token):
        // safe only when a mail carrying this fresh link is about to go out.
        if ($this->token_hash !== self::hashToken($derived)) {
            $this->update(['token_hash' => self::hashToken($derived)]);
        }

        return $derived;
    }

    public static function tokenFor(int $requestId, string $email): string
    {
        return hash_hmac('sha256', $requestId.'|'.mb_strtolower(trim($email)), (string) config('app.key'));
    }
}
