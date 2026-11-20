<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\MyIncidents;
use App\Support\PanelHome;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class CustomProfilePage extends BaseEditProfile
{
    // Owner 2026-11-20 (profile revamp): a custom page view — profile-land
    // shell, info block, contribution graph, right sub-menu nav — while the
    // form block stays byte-identical to the vendor view, so the EditProfile
    // save() pipeline (session password_hash_web refresh) is untouched.
    protected static string $view = 'filament.pages.profile';

    // The panel's profile page is SIMPLE by default (Filament::isProfilePageSimple)
    // → a centered card with no topbar/sidebar. Profile-land needs the full
    // panel layout: the topbar with the ☰ + logo (owner 2026-11-20).
    protected static string $layout = 'filament-panels::components.layout.index';

    /** @var array<string, mixed> */
    public array $contributionGraph = [];

    public function mount(): void
    {
        parent::mount();

        $this->contributionGraph = MyIncidents::contributionGraph($this->getUser());
    }

    // Owner 2026-11-20 (revisi): the page is the PROFILE page — heading and
    // browser title follow the menu; no subheading.
    public function getTitle(): string
    {
        return __('Profile');
    }

    public function getHeading(): string
    {
        return __('Profile');
    }

    /**
     * Owner 2026-11-20 (revisi): the change-password form (revealed behind a
     * button) takes OLD password / NEW password / CONFIRM. The name/email
     * fields left the form — the info block shows them read-only.
     *
     * The vendor EditProfile::save() pipeline is kept AS-IS: it persists
     * `$data['password']` (the hash) and refreshes the session
     * `password_hash_{guard}` that AuthenticateSession compares against —
     * dropping that session refresh force-logs users out on every change.
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('current_password')
                    ->label(__('Old password'))
                    ->password()
                    ->required()
                    ->rule('current_password')
                    ->dehydrated(false),
                TextInput::make('password')
                    ->label(__('New password'))
                    ->password()
                    ->required()
                    ->rule(Password::default())
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->confirmed(),
                TextInput::make('password_confirmation')
                    ->label(__('Confirm new password'))
                    ->password()
                    ->required()
                    ->dehydrated(false),
            ]);
    }

    protected function getSaveFormAction(): Action
    {
        return Action::make('save')
            ->label(__('filament-panels::pages/auth/edit-profile.form.actions.save.label'))
            ->submit('save')
            ->keyBindings(['mod+s']);
    }

    protected function getRedirectUrl(): ?string
    {
        // Same rule as login: user-role accounts cannot open the Dashboard
        // (canAccess requires `manage incidents`) — unconditional Dashboard
        // here was a guaranteed 403 for them after saving.
        return PanelHome::urlFor(auth()->user());
    }
}
