<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAuditLogSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'allowed_years',
        'can_view_all_logs',
    ];

    protected $casts = [
        'allowed_years' => 'array',
        'can_view_all_logs' => 'boolean',
    ];

    /**
     * Get the user that owns the audit log settings.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get or create settings for a user.
     * Defaults: admins get full access, non-admins get current year only
     */
    public static function forUser(User $user): self
    {
        return static::firstOrCreate(
            ['user_id' => $user->id],
            [
                'allowed_years' => [(int) date('Y')],
                'can_view_all_logs' => $user->hasRole('admin'),
            ]
        );
    }

    /**
     * Check if user can view logs for a specific year
     */
    public function canViewYear(int $year): bool
    {
        if ($this->can_view_all_logs) {
            return true;
        }

        return in_array($year, $this->allowed_years ?? []);
    }

    /**
     * Check if user can view logs for a specific date
     */
    public function canViewDate($date): bool
    {
        if (is_string($date)) {
            $date = new \DateTime($date);
        }

        $year = (int) $date->format('Y');

        return $this->canViewYear($year);
    }

    /**
     * Get allowed years as a comma-separated string for display
     */
    public function getAllowedYearsStringAttribute(): string
    {
        if ($this->can_view_all_logs) {
            return 'All Years';
        }

        return empty($this->allowed_years)
            ? 'None'
            : implode(', ', $this->allowed_years);
    }
}
