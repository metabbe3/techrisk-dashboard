<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
        Permission::firstOrCreate(['name' => 'access dashboard']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->givePermissionTo('access dashboard');
    }

    private function notificationsFor(User $user, string $type): int
    {
        return DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('notifiable_type', User::class)
            ->where('data', 'like', '%"type":"' . $type . '"%')
            ->count();
    }

    public function test_announcement_page_is_accessible_by_admin(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/send-announcement');

        $response->assertSuccessful();
    }

    public function test_announcement_page_is_forbidden_for_non_admin(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo('access dashboard');

        $response = $this->actingAs($user)
            ->get('/admin/send-announcement');

        $response->assertForbidden();
    }

    public function test_admin_can_send_announcement_to_all_users(): void
    {
        $user1 = User::factory()->create();
        $user1->assignRole('user');

        $user2 = User::factory()->create();
        $user2->assignRole('user');

        $announcement = new \App\Notifications\AdminAnnouncement(
            title: 'Test Announcement',
            body: 'This is a test message.',
            url: 'https://example.com',
        );

        collect([$this->admin, $user1, $user2])->each(fn ($u) => $u->notify($announcement));

        $this->assertEquals(1, $this->notificationsFor($this->admin, 'admin_announcement'));
        $this->assertEquals(1, $this->notificationsFor($user1, 'admin_announcement'));
        $this->assertEquals(1, $this->notificationsFor($user2, 'admin_announcement'));
    }

    public function test_admin_announcement_respects_user_preferences(): void
    {
        $user = User::factory()->create();
        \App\Models\NotificationPreference::forUser($user)->update([
            'email_admin_announcement' => false,
            'database_admin_announcement' => false,
        ]);

        $user->notify(new \App\Notifications\AdminAnnouncement(
            title: 'Test',
            body: 'Body',
        ));

        $this->assertEquals(0, $this->notificationsFor($user, 'admin_announcement'));
    }
}
