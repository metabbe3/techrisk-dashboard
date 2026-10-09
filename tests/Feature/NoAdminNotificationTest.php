<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Severity;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Owner rule 2026-10-09: NO automated email/notification ever goes to
 * admins. The PIC-assignment admin blast and the P1/P2 critical-incident
 * admin alert were the last two paths — both removed.
 */
class NoAdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'log']);
    }

    public function test_pic_assignment_never_notifies_admins(): void
    {
        Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $pic = User::factory()->create();

        $incident = Incident::factory()->createQuietly();
        $incident->pics()->attach($pic->id);

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $admin->id)->count());
        // The assignee still gets their own assignment notification.
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $pic->id)->count());
    }

    public function test_critical_incident_never_notifies_admins(): void
    {
        Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Incident::factory()->create(['severity' => Severity::P1->value]);

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $admin->id)->count());
    }
}
