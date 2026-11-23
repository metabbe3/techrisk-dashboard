<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Owner 2026-11-17: the browser tab showed the DEFAULT icon —
 * public/favicon.ico was an empty (0-byte) file and no panel favicon was
 * configured. The favicon is the DANA logo, square-cropped from the
 * downloaded artwork.
 */
class FaviconTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_panel_renders_a_favicon_link(): void
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->get('/admin')
            ->assertSuccessful()
            ->assertSeeHtml('<link rel="icon"', false);
    }

    /**
     * Owner 2026-11-23 (prod showed the DEFAULT icon while local was fine):
     * `asset()` builds an ABSOLUTE URL from APP_URL — a stale/mismatched prod
     * APP_URL pointed the link at the wrong host, it 404'd, and the browser
     * fell back to /favicon.ico. The favicon reference must be ROOT-RELATIVE
     * (no host, no scheme — immune to APP_URL), cache-busted with ?v=4.
     */
    public function test_the_favicon_link_is_root_relative_so_prod_app_url_cannot_break_it(): void
    {
        $html = (string) $this->actingAs($this->adminUser())->get('/admin')->assertSuccessful()->getContent();

        $this->assertStringContainsString('href="/images/favicon.png?v=4"', $html);

        $provider = (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
        $this->assertStringNotContainsString('->favicon(asset(', $provider, 'asset() re-introduces the APP_URL dependency that broke the prod favicon');
    }

    /**
     * Owner 2026-11-17: the DANA brand logo beside "TechRisk Portal" first
     * shipped as a plain string — Filament treats that as an image PATH and
     * dropped the whole HTML into src="", rendering the broken-image alt
     * text instead. brandLogo() must receive an Htmlable for markup.
     */
    public function test_the_brand_logo_renders_the_dana_mark_as_a_real_image(): void
    {
        // ?v=N cache-busts each rebuilt mark — the same URL kept serving the
        // previous bytes (owner-supplied artwork is now version 3).
        $this->actingAs($this->adminUser())
            ->get('/admin')
            ->assertSuccessful()
            ->assertSeeHtml('<img src="'.asset('images/favicon.png').'?v=3"', false);
    }

    /**
     * Owner 2026-11-17 (RISE reference): the collapsed sidebar shows NO logo
     * — the brand parks in the blue topbar instead, injected at TOPBAR_START
     * and Alpine-hidden while the sidebar is open.
     */
    public function test_the_topbar_carries_the_brand_for_the_collapsed_sidebar(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/admin')
            ->assertSuccessful()
            ->assertSeeHtml('fi-topbar-brand', false)
            ->assertSeeHtml('x-show="! $store.sidebar.isOpen"', false);
    }

    private function adminUser(): User
    {
        Role::firstOrCreate(['name' => 'admin']);
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'view dashboard widgets']);
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo(['view incidents', 'view dashboard widgets']);

        return $user;
    }

    public function test_the_browsers_default_favicon_request_is_a_real_image(): void
    {
        // The browser requests /favicon.ico even with no link tag. The file
        // used to be 0 bytes, so the tab showed the default icon.
        $ico = public_path('favicon.ico');

        $this->assertFileExists($ico);
        $this->assertGreaterThan(0, filesize($ico));
        $this->assertSame(pack('vvv', 0, 1, 6), substr((string) file_get_contents($ico), 0, 6), 'must be a valid .ICO (ICONDIR: reserved=0, type=1, count>=1)');
    }

    public function test_the_favicon_image_is_square(): void
    {
        $png = public_path('images/favicon.png');

        $this->assertFileExists($png);
        [$width, $height] = getimagesize($png);
        $this->assertSame($width, $height, 'favicon must be square (the source artwork is 3:2)');
        $this->assertSame(256, $width);
    }
}
