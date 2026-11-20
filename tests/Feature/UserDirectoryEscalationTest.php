<?php

namespace Tests\Feature;

use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\ActionImprovementsRelationManager;
use App\Mail\GroupNotificationMail;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\User;
use Filament\Forms\Form;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PIC directory on the users table + org-tree escalation (owner request
 * 2026-10-13): action-improvement PIC emails become login-disabled directory
 * users, the form suggests them by name, and overdue actions escalate up the
 * manager chain every 2 weeks (L1..L3 capped).
 */
class UserDirectoryEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_upsert_normalizes_tags_and_creates_login_disabled_users(): void
    {
        $existing = User::factory()->create(['name' => 'Real Name']);

        $result = User::upsertFromPicEmails([
            'Budi Santoso <budi@dana.id>',
            'new@x.id',
            'not an email',
            'Budi Santoso <budi@dana.id>', // dupe tag
        ]);

        $this->assertSame(['budi@dana.id', 'new@x.id'], $result);

        $budi = User::where('email', 'budi@dana.id')->first();
        $this->assertNotNull($budi);
        $this->assertSame('Budi Santoso', $budi->name);
        $this->assertFalse($budi->can_login, 'directory contacts cannot log in until flipped');
        $this->assertNotEquals($existing->password, $budi->password);

        // Bare email gets a name from the local part.
        $this->assertSame('new', User::where('email', 'new@x.id')->value('name'));

        // Existing users are never touched — same email keeps name + login.
        User::upsertFromPicEmails(['Someone Else <'.$existing->email.'>']);
        $this->assertTrue($existing->refresh()->can_login);
        $this->assertSame('Real Name', $existing->refresh()->name);
    }

    public function test_saving_an_action_normalizes_tags_and_upserts_the_directory(): void
    {
        ActionImprovement::factory()->create([
            'incident_id' => Incident::factory()->create()->id,
            'pic_email' => ['Budi Santoso <budi@dana.id>'],
        ]);

        $this->assertSame(
            ['budi@dana.id'],
            ActionImprovement::query()->latest('id')->first()->pic_email
        );
        $this->assertDatabaseHas('users', ['email' => 'budi@dana.id', 'can_login' => false]);
    }

    public function test_can_access_panel_requires_the_login_flag(): void
    {
        $panel = Panel::make()->id('admin');

        // Migration default true — every existing user keeps access.
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        $human = User::factory()->create();
        $human->assignRole('admin');
        $this->assertTrue($human->canAccessPanel($panel));

        // Directory contact: blocked even with a role, until flipped.
        User::upsertFromPicEmails(['Budi <budi@dana.id>']);
        $budi = User::where('email', 'budi@dana.id')->first();
        $budi->assignRole('admin');
        $this->assertFalse($budi->canAccessPanel($panel));

        $budi->forceFill(['can_login' => true])->save();
        $this->assertTrue($budi->refresh()->canAccessPanel($panel));
    }

    private function directoryUserWithChain(int $managersAbove): User
    {
        User::upsertFromPicEmails(['Pic Person <pic@dana.id>']);
        $pic = User::where('email', 'pic@dana.id')->first();

        $above = null;
        for ($i = $managersAbove; $i >= 1; $i--) {
            $above = User::factory()->create(['manager_id' => $above?->id]);
        }
        $pic->forceFill(['manager_id' => $above?->id])->saveQuietly();

        return $pic;
    }

    private function overdueActionFor(User $pic, int $daysOverdue): ActionImprovement
    {
        return ActionImprovement::factory()->create([
            'incident_id' => Incident::factory()->create()->id,
            'pic_email' => [$pic->email],
            'reminder' => true,
            'status' => 'pending',
            'due_date' => now()->subDays($daysOverdue)->startOfDay(),
        ]);
    }

    private function escalatedRows(User $notifiable): int
    {
        return DB::table('notifications')
            ->where('notifiable_id', $notifiable->id)
            ->where('data', 'like', '%"type":"action_improvement_escalated"%')
            ->count();
    }

    public function test_two_weeks_overdue_escalates_to_manager_once(): void
    {
        $pic = $this->directoryUserWithChain(managersAbove: 2);
        $manager = $pic->manager;
        $action = $this->overdueActionFor($pic, 15);

        Mail::fake();
        $this->artisan('reminders:send-action-improvements')->assertSuccessful();

        // Overdue reminder to the PIC + escalation mail to the manager.
        Mail::assertSent(GroupNotificationMail::class, 2);
        $this->assertSame(1, $this->escalatedRows($manager));
        $this->assertSame(1, $action->refresh()->escalation_level);
        $this->assertNotNull($action->last_escalated_at);

        // Directory PIC now receives the reminder email (previously a
        // non-User email got nothing) — reminders_sent proves the mail half.
        $this->assertSame(1, $action->refresh()->reminders_sent);

        // Second run: same level never fires twice.
        $this->artisan('reminders:send-action-improvements')->assertSuccessful();
        $this->assertSame(1, $this->escalatedRows($manager));
        $this->assertSame(1, $action->refresh()->escalation_level);
    }

    public function test_longer_overdue_climbs_higher_and_caps_at_three(): void
    {
        // 29 days, chain 2 deep → L1 and L2 both fire on the first run
        // (a row already deep in overdue informs every rung it passed).
        $pic = $this->directoryUserWithChain(managersAbove: 2);
        $action = $this->overdueActionFor($pic, 29);

        $this->artisan('reminders:send-action-improvements')->assertSuccessful();

        $manager = $pic->manager;
        $grandManager = $manager->manager;
        $this->assertSame(1, $this->escalatedRows($manager), 'L1 rung still informed');
        $this->assertSame(1, $this->escalatedRows($grandManager), 'L2 notifies the grand-manager');
        $this->assertSame(2, $action->refresh()->escalation_level);

        // 45 days demands L3 (capped) but the chain is only 2 deep → the
        // reachable rungs fired, level stops at the highest fired.
        $pic2 = $this->directoryUserWithChain(managersAbove: 2);
        $action2 = $this->overdueActionFor($pic2, 45);

        $this->artisan('reminders:send-action-improvements')->assertSuccessful();

        $this->assertSame(2, $action2->refresh()->escalation_level, 'level = highest rung actually reached');
    }

    public function test_no_manager_chain_no_escalation(): void
    {
        $pic = $this->directoryUserWithChain(managersAbove: 0);
        $action = $this->overdueActionFor($pic, 20);

        $this->artisan('reminders:send-action-improvements')->assertSuccessful();

        $this->assertSame(0, $action->refresh()->escalation_level);
        $this->assertNull($action->last_escalated_at);
    }

    /**
     * PIC picker is the incident-style dropdown (owner request 2026-10-13):
     * options keyed by email over human users (directory contacts included)
     * + the built-in "+" createOption action. Driving the real Create table
     * action proves the email-keyed multiple Select round-trips bare emails.
     */
    public function test_pic_picker_dropdown_lists_directory_contacts_with_plus_create(): void
    {
        User::upsertFromPicEmails(['Budi Santoso <budi@dana.id>']);
        $regular = User::factory()->create(['name' => 'Regular Person']);
        $incident = Incident::factory()->create();

        Permission::firstOrCreate(['name' => 'manage incidents']);
        $this->actingAs(User::factory()->create()->givePermissionTo('manage incidents'));

        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])
            ->assertSuccessful()
            ->callTableAction('create', data: [
                'title' => 'Fix the thing',
                'detail' => 'Do it properly',
                'due_date' => now()->addWeeks(2)->toDateString(),
                'pic_email' => ['budi@dana.id', $regular->email],
            ]);

        $action = ActionImprovement::query()->latest('id')->first();
        $this->assertSame(['budi@dana.id', $regular->email], $action->pic_email, 'dropdown-picked emails store as bare emails');

        // The "+" createOption path is the single-home upsert under a thin
        // tag-building shell — same input shape it already covers.
        $created = User::upsertFromPicEmails(['Fresh Contact <fresh@dana.id>']);
        $this->assertSame(['fresh@dana.id'], $created);
        $this->assertFalse(User::where('email', 'fresh@dana.id')->value('can_login'));
    }
}
