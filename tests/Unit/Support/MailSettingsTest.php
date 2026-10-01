<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Setting;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * T1: mail activation is decided at RUNTIME from the settings the Email
 * Settings page owns — no .env editing. Netcore becomes the default mailer
 * when the toggle is on AND a key exists; the From address/name always come
 * from settings (default noreply-techrisk@dana.id). Boot-safe when the
 * settings table does not exist yet (fresh install / pre-migration test boot).
 */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'log']); // env state must not leak between tests
    }

    public function test_activates_netcore_when_enabled_with_key(): void
    {
        Setting::set('netcore_enabled', true);
        Setting::set('netcore_api_key', 'key-123');

        MailSettings::apply();

        $this->assertSame('netcore', config('mail.default'));
    }

    public function test_does_not_activate_when_toggle_off(): void
    {
        Setting::set('netcore_enabled', false);
        Setting::set('netcore_api_key', 'key-123');

        MailSettings::apply();

        $this->assertSame('log', config('mail.default'));
    }

    public function test_does_not_activate_without_key(): void
    {
        Setting::set('netcore_enabled', true);
        Setting::set('netcore_api_key', null);

        MailSettings::apply();

        $this->assertSame('log', config('mail.default'));
    }

    public function test_from_address_and_name_come_from_settings(): void
    {
        Setting::set('mail_from_address', 'noreply-techrisk@dana.id');
        Setting::set('mail_from_name', 'TechRisk');

        MailSettings::apply();

        $this->assertSame('noreply-techrisk@dana.id', config('mail.from.address'));
        $this->assertSame('TechRisk', config('mail.from.name'));
    }

    public function test_from_falls_back_to_dana_default_when_unset(): void
    {
        MailSettings::apply();

        $this->assertSame('noreply-techrisk@dana.id', config('mail.from.address'));
    }

    public function test_apply_is_boot_safe_without_settings_table(): void
    {
        Schema::drop('settings');

        MailSettings::apply(); // must not throw

        $this->assertSame('log', config('mail.default'));
        $this->assertSame('noreply-techrisk@dana.id', config('mail.from.address'));
    }
}
