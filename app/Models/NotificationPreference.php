<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        // Email preferences
        'email_incident_assignment',
        'email_incident_update',
        'email_incident_status_changed',
        'email_status_update',
        'email_action_improvement_reminder',
        'email_action_improvement_overdue',
        // Database preferences
        'database_incident_assignment',
        'database_incident_update',
        'database_incident_status_changed',
        'database_status_update',
        'database_action_improvement_reminder',
        'database_action_improvement_overdue',
    ];

    protected $casts = [
        'email_incident_assignment' => 'boolean',
        'email_incident_update' => 'boolean',
        'email_incident_status_changed' => 'boolean',
        'email_status_update' => 'boolean',
        'email_action_improvement_reminder' => 'boolean',
        'email_action_improvement_overdue' => 'boolean',
        'database_incident_assignment' => 'boolean',
        'database_incident_update' => 'boolean',
        'database_incident_status_changed' => 'boolean',
        'database_status_update' => 'boolean',
        'database_action_improvement_reminder' => 'boolean',
        'database_action_improvement_overdue' => 'boolean',
    ];

    /**
     * Get the user that owns the notification preferences.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get email notification preferences for a given type.
     */
    public function getEmailPreference(string $type): bool
    {
        $key = "email_{$type}";
        return $this->getAttribute($key) ?? true;
    }

    /**
     * Get database notification preferences for a given type.
     */
    public function getDatabasePreference(string $type): bool
    {
        $key = "database_{$type}";
        return $this->getAttribute($key) ?? true;
    }

    /**
     * Get or create preferences for a user.
     */
    public static function forUser(User $user): self
    {
        return static::firstOrCreate(
            ['user_id' => $user->id],
            []
        );
    }

    /**
     * Count enabled email notifications.
     */
    public function getEmailEnabledCountAttribute(): int
    {
        return (
            ($this->email_incident_assignment ? 1 : 0) +
            ($this->email_incident_update ? 1 : 0) +
            ($this->email_incident_status_changed ? 1 : 0) +
            ($this->email_status_update ? 1 : 0) +
            ($this->email_action_improvement_reminder ? 1 : 0) +
            ($this->email_action_improvement_overdue ? 1 : 0)
        );
    }

    /**
     * Count enabled database notifications.
     */
    public function getDatabaseEnabledCountAttribute(): int
    {
        return (
            ($this->database_incident_assignment ? 1 : 0) +
            ($this->database_incident_update ? 1 : 0) +
            ($this->database_incident_status_changed ? 1 : 0) +
            ($this->database_status_update ? 1 : 0) +
            ($this->database_action_improvement_reminder ? 1 : 0) +
            ($this->database_action_improvement_overdue ? 1 : 0)
        );
    }
}
