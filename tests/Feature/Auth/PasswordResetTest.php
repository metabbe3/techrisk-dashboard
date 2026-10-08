<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Filament\Notifications\Auth\ResetPassword as ResetPasswordNotification;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset;
use Filament\Pages\Auth\PasswordReset\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Forgot/reset password: Filament's vendor pages (enabled via ->passwordReset()
 * on the panel) + the branded reset email installed via ResetPassword::toMailUsing().
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Factory users carry no role, so canAccessPanel() is false and the
     * broker callback silently skips notify() — no mail is ever sent.
     */
    private function panelUser(): User
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'admin']);
        $user->assignRole('admin');

        return $user->refresh();
    }

    public function test_guest_can_view_the_request_page(): void
    {
        $this->get('/admin/password-reset/request')->assertOk();
    }

    public function test_login_page_shows_forgot_password_link(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Forgot password?')
            ->assertSee(route('filament.admin.auth.password-reset.request'));
    }

    public function test_requesting_a_reset_link_sends_notification_and_stores_token(): void
    {
        Notification::fake();
        $user = $this->panelUser();

        Livewire::test(RequestPasswordReset::class)
            ->set('data.email', $user->email)
            ->call('request')
            ->assertHasNoErrors();

        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            fn ($notification) => str_contains($notification->url, '/admin/password-reset/reset')
        );
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_requesting_a_reset_for_unknown_email_sends_nothing(): void
    {
        Notification::fake();

        Livewire::test(RequestPasswordReset::class)
            ->set('data.email', 'nobody@example.com')
            ->call('request');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_reset_mail_is_branded(): void
    {
        $user = User::factory()->create();

        $mail = (new ResetPasswordNotification('test-token'))->toMail($user);

        $this->assertSame('TechRisk Portal — Reset Password', $mail->subject);
        $this->assertSame('emails.incident-reminder', $mail->view);

        $data = $mail->viewData;
        $this->assertSame('Hello '.$user->name.',', $data['greeting']);
        $this->assertSame('Reset your password', $data['headline']);
        $this->assertSame('Reset Password', $data['actionText']);
        $this->assertSame([], $data['details']);
        $this->assertStringContainsString('expires in 60 minutes', $data['intro']);
        $this->assertStringContainsString('did not request a password reset', $data['intro']);
        $this->assertStringContainsString('Technical Risk Portal', $data['footer']);
        $this->assertStringContainsString('/admin/password-reset/reset', $data['actionUrl']);
        $this->assertStringContainsString(urlencode($user->email), $data['actionUrl']);
        $this->assertTrue(URL::hasValidSignature(Request::create($data['actionUrl'])));
    }

    public function test_reset_page_changes_password_end_to_end(): void
    {
        $user = $this->panelUser();
        $token = Password::broker('users')->createToken($user);

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->set('password', 'NewPassword123!')
            ->set('passwordConfirmation', 'NewPassword123!')
            ->call('resetPassword')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword123!', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_reset_with_wrong_token_does_not_change_password(): void
    {
        $user = $this->panelUser();
        Password::broker('users')->createToken($user);

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => 'wrong-token'])
            ->set('password', 'NewPassword123!')
            ->set('passwordConfirmation', 'NewPassword123!')
            ->call('resetPassword');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }
}
