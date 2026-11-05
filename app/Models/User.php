<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ChannelFilteredNotification;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements Auditable, FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    use \OwenIt\Auditing\Auditable;

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->is_service_account) {
            return false;
        }

        // Directory-only contact (auto-created from an action-improvement
        // PIC email) — flip can_login in User Management to grant access.
        // Strict false: a not-yet-hydrated null must never block a real user.
        if ($this->can_login === false) {
            return false;
        }

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
     * Single source for the dashboard-widgets gate: the read-only
     * `view dashboard widgets` permission OR the historical `manage incidents`
     * access. Dashboard page, PanelHome and RiskHeatMatrixWidget must all
     * agree — change the rule here only.
     */
    public function canViewDashboardWidgets(): bool
    {
        return $this->can('manage incidents') || $this->can('view dashboard widgets');
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

    public function aiUsageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
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

        $modifiedNotification = new ChannelFilteredNotification($instance, $channels);

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
            \App\Notifications\ActionImprovementEscalated::class => 'action_improvement_escalated',
            \App\Notifications\ActionImprovementsSuggested::class => 'action_improvement_suggested',
            \App\Notifications\ActionImprovementAssigned::class => 'action_improvement_assigned',
            \App\Notifications\AdminAnnouncement::class => 'admin_announcement',
            \App\Notifications\IncidentNotDoneReminder::class => 'incident_not_done_reminder',
            \App\Notifications\FundLossUnsettledReminder::class => 'fund_loss_unsettled_reminder',
            default => null,
        };
    }

    /**
     * Mail-eligibility check for the combined reminder email (Support\ReminderMail):
     * unknown notification types send unfiltered, known ones follow the
     * user's email_{$type} preference.
     */
    public function mailPreferenceAllows($instance): bool
    {
        $type = $this->getNotificationType($instance);

        return $type === null
            || NotificationPreference::forUser($this)->getEmailPreference($type);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'access_expiry',
        'is_service_account',
        'can_login',
        'manager_id',
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
            'is_service_account' => 'boolean',
            'can_login' => 'boolean',
        ];
    }

    /**
     * Org tree (owner request 2026-10-13): drives action-improvement
     * escalation up the manager chain.
     */
    public function manager()
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /**
     * The form tag format — "Name <email>" (from our own suggestions) or a
     * bare email. Returns [?name, email] or null when the tag is garbage.
     *
     * @return array{0: ?string, 1: string}|null
     */
    public static function parsePicEmailTag(string $tag): ?array
    {
        if (preg_match('/^(.*?)<\s*([^<>\s]+@[^<>\s]+)\s*>$/', trim($tag), $m)) {
            $name = trim($m[1]);
            $email = strtolower($m[2]);

            return filter_var($email, FILTER_VALIDATE_EMAIL) ? [($name !== '' ? $name : null), $email] : null;
        }

        $email = strtolower(trim($tag));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? [null, $email] : null;
    }

    /**
     * Single home of the PIC directory (owner request 2026-10-13): parse form
     * tags (bare emails or "Name <email>" suggestions), upsert every address
     * as a user, and return the normalized bare-email array to store on
     * pic_email — downstream User-matching and mail are unchanged, except
     * these contacts now actually receive the reminder emails.
     *
     * Directory rows are login-disabled (can_login=false, random password);
     * flip the toggle in User Management when a contact needs access.
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    public static function upsertFromPicEmails(array $tags): array
    {
        $emails = [];
        $namesByEmail = [];

        foreach ($tags as $tag) {
            if ($parsed = self::parsePicEmailTag((string) $tag)) {
                [$name, $email] = $parsed;
                $emails[$email] = $email;
                $namesByEmail[$email] ??= $name;
            }
        }

        foreach ($emails as $email) {
            self::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $namesByEmail[$email] ?? strtok($email, '@'),
                    // Random + unknowable: access is granted via the
                    // can_login toggle, never this password.
                    'password' => \Illuminate\Support\Str::password(40),
                    'can_login' => false,
                ],
            );
        }

        return array_values($emails);
    }

    /**
     * The ancestor exactly $level steps up the manager chain. Null when the
     * chain is shorter or loops.
     */
    public function ancestorAt(int $level): ?self
    {
        $current = $this;
        $seen = [$this->id];

        for ($i = 0; $i < $level; $i++) {
            $manager = $current->manager()->first();
            if (! $manager || in_array($manager->id, $seen)) {
                return null;
            }
            $seen[] = $manager->id;
            $current = $manager;
        }

        return $current;
    }

    public function scopeServiceAccounts($query): void
    {
        $query->where('is_service_account', true);
    }

    public function scopeHumanUsers($query): void
    {
        $query->where('is_service_account', false);
    }
}
