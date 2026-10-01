<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\EmailSettings;
use App\Models\Setting;
use App\Models\User;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * T2/T3: Email Settings owns mail activation end-to-end — From address/name
 * editable, real API key visible (revealable), status panel instead of the
 * "set .env" hint, save applies immediately + restarts queue workers, and a
 * send-test-email action surfaces the exact provider error on failure.
 */
class EmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        Permission::firstOrCreate(['name' => 'manage api tokens']);
        $user = User::factory()->create();
        $user->givePermissionTo('manage api tokens');

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'log']);
    }

    public function test_saving_from_fields_persists_and_applies_immediately(): void
    {
        Livewire::actingAs($this->manager())
            ->test(EmailSettings::class)
            ->set('data.netcore_enabled', true)
            ->set('data.api_key', 'key-123')
            ->set('data.from_address', 'noreply-techrisk@dana.id')
            ->set('data.from_name', 'TechRisk Dashboard')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('noreply-techrisk@dana.id', Setting::get('mail_from_address'));
        $this->assertSame('TechRisk Dashboard', Setting::get('mail_from_name'));
        $this->assertSame('key-123', Setting::get('netcore_api_key'));

        MailSettings::apply();
        $this->assertSame('netcore', config('mail.default'));
        $this->assertSame('noreply-techrisk@dana.id', config('mail.from.address'));
        $this->assertSame('TechRisk Dashboard', config('mail.from.name'));
    }

    public function test_status_panel_reflects_configuration(): void
    {
        // No key -> not active
        $html = Livewire::actingAs($this->manager())->test(EmailSettings::class)->html();
        $this->assertStringContainsString('No API key', $html);

        // Enabled + key -> active
        Setting::set('netcore_enabled', true);
        Setting::set('netcore_api_key', 'key-123');
        $html = Livewire::actingAs($this->manager())->test(EmailSettings::class)->html();
        $this->assertStringContainsString('Active', $html);

        // Toggle off -> suppressed
        Setting::set('netcore_enabled', false);
        $html = Livewire::actingAs($this->manager())->test(EmailSettings::class)->html();
        $this->assertStringContainsString('Suppressed', $html);
    }

    public function test_api_key_field_shows_the_real_saved_key(): void
    {
        Setting::set('netcore_api_key', 'real-secret-key-42');

        $html = Livewire::actingAs($this->manager())
            ->test(EmailSettings::class)
            ->html();

        $this->assertStringContainsString('real-secret-key-42', $html, 'owner must be able to see the saved key');
    }

    public function test_clearing_api_key_removes_it(): void
    {
        Setting::set('netcore_api_key', 'old-key');

        Livewire::actingAs($this->manager())
            ->test(EmailSettings::class)
            ->set('data.api_key', '')
            ->call('save');

        $this->assertNull(Setting::get('netcore_api_key'));
    }

    public function test_send_test_email_delivers_via_netcore(): void
    {
        config(['mail.default' => 'netcore', 'mail.mailers.netcore.api_key' => 'key-123']);
        Http::fake(['*' => Http::response(['status' => 'success'])]);

        Livewire::actingAs($this->manager())
            ->test(EmailSettings::class)
            ->set('data.test_to', 'owner@dana.id')
            ->call('sendTestEmail')
            ->assertNotified('Test email sent');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v5/mail/send')
            && $request->hasHeader('api_key', 'key-123'));
    }

    public function test_send_test_email_surfaces_the_exact_provider_error(): void
    {
        config(['mail.default' => 'netcore', 'mail.mailers.netcore.api_key' => 'key-123']);
        Http::fake(['*' => Http::response(['message' => 'Sender domain not approved'], 500)]);

        Livewire::actingAs($this->manager())
            ->test(EmailSettings::class)
            ->set('data.test_to', 'owner@dana.id')
            ->call('sendTestEmail');

        // Mounting the Notifications component consumes flash data — mount
        // once and read both title and body from the same collection.
        $component = new \Filament\Notifications\Livewire\Notifications;
        $component->mount();
        $failed = collect($component->notifications)->first(fn ($n) => $n->getTitle() === 'Test email failed');
        $this->assertNotNull($failed, 'failure notification must appear');
        $this->assertStringContainsString('Sender domain not approved', (string) $failed->getBody(), 'exact provider error must reach the admin');
    }

    public function test_recent_failures_panel_lists_only_netcore_failed_jobs(): void
    {
        $now = now()->format('Y-m-d H:i:s');
        DB::table('failed_jobs')->insert([
            ['uuid' => 'netcore-uuid-1', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'Netcore request failed: HTTP 500 — {"message":"domain blocked"}', 'failed_at' => $now],
            ['uuid' => 'other-uuid-2', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'SomeOtherWorker exploded', 'failed_at' => $now],
        ]);

        $html = Livewire::actingAs($this->manager())->test(EmailSettings::class)->html();

        $this->assertStringContainsString('netcore-uuid-1', $html);
        $this->assertStringContainsString('domain blocked', $html);
        $this->assertStringNotContainsString('other-uuid-2', $html);
    }
}
