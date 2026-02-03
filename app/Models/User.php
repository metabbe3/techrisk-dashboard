<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        // Check if user's access has expired
        if ($this->isAccessExpired()) {
            return false;
        }

        // Admins always have access
        if ($this->hasRole('admin')) {
            return true;
        }

        // Non-admins need 'access dashboard' permission
        return $this->can('access dashboard');
    }

    /**
     * Check if user's access has expired.
     */
    public function isAccessExpired(): bool
    {
        if (! $this->access_expiry) {
            return false;
        }

        return $this->access_expiry->isPast();
    }

    /**
     * Get the number of days until access expires.
     */
    public function daysUntilExpiry(): ?int
    {
        if (! $this->access_expiry) {
            return null;
        }

        if ($this->access_expiry->isPast()) {
            return 0;
        }

        return now()->diffInDays($this->access_expiry);
    }

    /**
     * Get the user's notification preferences.
     */
    public function notificationPreferences(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    /**
     * Get the user's dashboard widget preferences.
     */
    public function dashboardPreferences(): HasMany
    {
        return $this->hasMany(\App\Models\UserDashboardPreference::class)->orderBy('sort_order');
    }

    /**
     * Get the user's audit log settings.
     */
    public function auditLogSettings(): HasOne
    {
        return $this->hasOne(\App\Models\UserAuditLogSetting::class);
    }

    /**
     * Override notify to respect user preferences.
     */
    public function notify($instance)
    {
        // Get or create preferences for this user
        $preferences = NotificationPreference::forUser($this);

        // Determine notification type from instance
        $notificationType = $this->getNotificationType($instance);

        if (! $notificationType) {
            // If unknown type, send by default - use Notification facade
            return \Illuminate\Support\Facades\Notification::send($this, $instance);
        }

        // Check if user wants this type of notification
        $channels = [];

        // Check email preference
        if (in_array('mail', $instance->via($this)) && $preferences->getEmailPreference($notificationType)) {
            $channels[] = 'mail';
        }

        // Check database preference
        if (in_array('database', $instance->via($this)) && $preferences->getDatabasePreference($notificationType)) {
            $channels[] = 'database';
        }

        // Check broadcast preference (same as database)
        if (in_array('broadcast', $instance->via($this)) && $preferences->getDatabasePreference($notificationType)) {
            $channels[] = 'broadcast';
        }

        // If user has disabled all channels for this type, don't send
        if (empty($channels)) {
            return;
        }

        // Get the original notification class for type preservation
        $originalNotificationClass = get_class($instance);

        // Create a proper notification wrapper that extends Notification base class
        $modifiedNotification = new class($instance, $channels, $originalNotificationClass) extends \Illuminate\Notifications\Notification
        {
            protected $notification;

            protected $allowedChannels;

            protected $originalType;

            public function __construct($notification, $allowedChannels, $originalType)
            {
                $this->notification = $notification;
                $this->allowedChannels = $allowedChannels;
                $this->originalType = $originalType;
            }

            public function via($notifiable)
            {
                return $this->allowedChannels;
            }

            /**
             * Override to preserve the original notification class type
             * This ensures Filament can properly identify and render the notification
             */
            public function databaseType()
            {
                return $this->originalType;
            }

            public function toMail($notifiable)
            {
                return $this->notification->toMail($notifiable);
            }

            public function toDatabase($notifiable)
            {
                return $this->notification->toDatabase($notifiable);
            }

            public function toArray($notifiable)
            {
                if (method_exists($this->notification, 'toArray')) {
                    return $this->notification->toArray($notifiable);
                }

                return [];
            }

            public function toBroadcast($notifiable)
            {
                if (method_exists($this->notification, 'toBroadcast')) {
                    return $this->notification->toBroadcast($notifiable);
                }

                return [];
            }

            public function __call($method, $arguments)
            {
                return $this->notification->$method(...$arguments);
            }

            public function __get($name)
            {
                return $this->notification->$name;
            }

            public function __isset($name)
            {
                return isset($this->notification->$name);
            }
        };

        // Use Notification facade instead of parent::notify()
        return \Illuminate\Support\Facades\Notification::send($this, $modifiedNotification);
    }

    /**
     * Get notification type from notification instance.
     */
    private function getNotificationType($instance): ?string
    {
        $class = get_class($instance);

        return match ($class) {
            \App\Notifications\AssignedAsPicNotification::class => 'incident_assignment',
            \App\Notifications\IncidentUpdated::class => 'incident_update',
            \App\Notifications\IncidentStatusChanged::class => 'incident_status_changed',
            \App\Notifications\NewStatusUpdate::class => 'status_update',
            \App\Notifications\ActionImprovementReminder::class => 'action_improvement_reminder',
            \App\Notifications\ActionImprovementDueSoon::class => 'action_improvement_reminder',
            \App\Notifications\ActionImprovementOverdue::class => 'action_improvement_overdue',
            default => null,
        };
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'access_expiry',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'access_expiry' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
