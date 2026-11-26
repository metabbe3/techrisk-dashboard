<?php

declare(strict_types=1);

namespace Tests\Feature\Doha;

use App\Filament\Pages\SystemSettings;
use App\Models\Setting;
use App\Services\Doha\DohaClient;
use App\Services\Doha\DohaSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DohaSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'manage api tokens']);
    }

    private function admin(): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo(['manage api tokens']);

        return $user;
    }

    public function test_gitignore_pins_doha_temp(): void
    {
        $gitignore = file_get_contents(base_path('.gitignore'));

        $this->assertStringContainsString('/doha_temp/', $gitignore,
            'the DOHA API guide is a local reference and must never be committed');
    }

    public function test_saving_a_token_encrypts_it_at_rest(): void
    {
        Livewire::actingAs($this->admin())
            ->test(SystemSettings::class)
            ->fillForm(['doha_api_token' => 'dhm_tok_secret'])
            ->call('save');

        $stored = Setting::get(DohaClient::TOKEN_KEY);

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('dhm_tok_secret', (string) $stored,
            'the token must never sit in the settings table as plaintext');

        $client = new DohaClient;
        $this->assertTrue($client->configured());
        $this->assertSame('dhm_tok_secret', $client->token());
    }

    public function test_saving_the_sentinel_keeps_the_current_token(): void
    {
        Setting::set(DohaClient::TOKEN_KEY, Crypt::encryptString('current-token'));

        Livewire::actingAs($this->admin())
            ->test(SystemSettings::class)
            ->call('save');

        // The ciphertext is non-deterministic — assert on the decrypted value.
        $this->assertSame('current-token',
            Crypt::decryptString((string) Setting::get(DohaClient::TOKEN_KEY)));
    }

    public function test_clearing_the_field_removes_the_token(): void
    {
        Setting::set(DohaClient::TOKEN_KEY, Crypt::encryptString('current-token'));

        Livewire::actingAs($this->admin())
            ->test(SystemSettings::class)
            ->fillForm(['doha_api_token' => ''])
            ->call('save');

        $this->assertNull(Setting::get(DohaClient::TOKEN_KEY));
    }

    public function test_mount_shows_the_sentinel_when_a_token_exists(): void
    {
        Setting::set(DohaClient::TOKEN_KEY, Crypt::encryptString('current-token'));

        $component = Livewire::actingAs($this->admin())
            ->test(SystemSettings::class);

        $this->assertSame('••••••••', $component->instance()->form->getRawState()['doha_api_token']);
    }

    public function test_sync_now_runs_the_sync_and_records_status(): void
    {
        Http::fake(['doha.dana.id/*' => Http::response([[]])]);
        Setting::set(DohaClient::TOKEN_KEY, Crypt::encryptString('current-token'));

        Livewire::actingAs($this->admin())
            ->test(SystemSettings::class)
            ->call('runDohaSync');

        $this->assertNotNull(Setting::get(DohaSyncService::LAST_SYNC_AT_KEY));
        $this->assertStringStartsWith('ok', (string) Setting::get(DohaSyncService::LAST_SYNC_STATUS_KEY));
    }
}
