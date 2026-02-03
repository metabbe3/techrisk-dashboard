<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessRequest extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'email',
        'password',
        'requested_duration_days',
        'requested_years',
        'reason',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'created_user_id',
    ];

    protected $casts = [
        'requested_years' => 'array',
        'approved_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Get the admin who approved this request.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user that was created from this request.
     */
    public function createdUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Check if the request is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the request is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if the request is rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Approve the request and create/update user account.
     */
    public function approve(int $approvedByUserId, ?string $accessExpiry = null): User
    {
        $user = User::firstOrCreate(
            ['email' => $this->email],
            [
                'name' => $this->name,
                'password' => $this->password,
            ]
        );

        // Set access expiry
        if ($accessExpiry) {
            $user->access_expiry = $accessExpiry;
        } else {
            // Use requested duration
            $user->access_expiry = now()->addDays($this->requested_duration_days);
        }

        // Ensure user has 'user' role and 'access dashboard' permission
        if (! $user->hasRole('user')) {
            $user->assignRole('user');
        }

        $user->save();

        // Set up audit log settings (year permissions)
        UserAuditLogSetting::updateOrCreate(
            ['user_id' => $user->id],
            [
                'allowed_years' => $this->requested_years,
                'can_view_all_logs' => false,
            ]
        );

        // Update request status
        $this->update([
            'status' => 'approved',
            'approved_by' => $approvedByUserId,
            'approved_at' => now(),
            'created_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Reject the request with a reason.
     */
    public function reject(int $approvedByUserId, ?string $reason = null): void
    {
        $this->update([
            'status' => 'rejected',
            'approved_by' => $approvedByUserId,
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Get the calculated expiry date based on requested duration.
     */
    public function getCalculatedExpiryAttribute(): string
    {
        return now()->addDays($this->requested_duration_days)->format('Y-m-d H:i:s');
    }
}
