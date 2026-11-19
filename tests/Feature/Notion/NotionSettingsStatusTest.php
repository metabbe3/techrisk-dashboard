<?php

namespace Tests\Feature\Notion;

use App\Filament\Pages\SystemSettings;
use App\Services\Notion\NotionMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner 2026-10-29: the connection button reads "Connect" when nothing is
 * connected and "Reconnect" once a token exists (a failed refresh keeps the
 * stored token, so the problem states stay on "Reconnect"). The status line
 * is color-coded — green + bold when healthy, red when not connected or
 * broken, amber while a renewal is pending.
 */
class NotionSettingsStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_connect_button_is_labeled_connect_when_not_connected(): void
    {
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(false);

        $this->assertSame('Connect', $this->connectActionLabel());
    }

    public function test_the_connect_button_is_labeled_reconnect_when_connected(): void
    {
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('needsReconnect')->andReturn(false)
            ->shouldReceive('tokenExpiresAt')->andReturn(time() + 3600);

        $this->assertSame('Reconnect', $this->connectActionLabel());
    }

    public function test_the_connect_button_is_labeled_reconnect_when_the_refresh_failed(): void
    {
        // The token set still exists (connected() true) but is unusable —
        // the operator needs "Reconnect", not a bare "Connect".
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('needsReconnect')->andReturn(true);

        $this->assertSame('Reconnect', $this->connectActionLabel());
    }

    public function test_a_healthy_connection_is_green_and_bold(): void
    {
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('needsReconnect')->andReturn(false)
            ->shouldReceive('tokenExpiresAt')->andReturn(time() + 3600);

        $this->assertSame('connected', app(SystemSettings::class)->connectionState());
        $this->assertStatusClasses(['text-success-600', 'font-bold']);
    }

    public function test_a_not_connected_status_is_red(): void
    {
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(false);

        $this->assertSame('not_connected', app(SystemSettings::class)->connectionState());
        $this->assertStatusClasses(['text-danger-600']);
    }

    public function test_a_required_reconnect_is_red(): void
    {
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('needsReconnect')->andReturn(true);

        $this->assertSame('reconnect_required', app(SystemSettings::class)->connectionState());
        $this->assertStatusClasses(['text-danger-600']);
    }

    public function test_a_pending_renewal_is_amber(): void
    {
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('needsReconnect')->andReturn(false)
            ->shouldReceive('tokenExpiresAt')->andReturn(time() - 60);

        $this->assertSame('renewal_pending', app(SystemSettings::class)->connectionState());
        $this->assertStatusClasses(['text-warning-600']);
    }

    private function connectActionLabel(): string
    {
        return app(SystemSettings::class)->connectButtonLabel();
    }

    private function assertStatusClasses(array $classes): void
    {
        $html = (string) app(SystemSettings::class)->connectionStatusHtml();

        foreach ($classes as $class) {
            $this->assertStringContainsString($class, $html);
        }
    }
}
