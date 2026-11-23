<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\PanelHome;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Owner 2026-11-20 (revisi 3): the change-password form moved OFF the profile
 * page into this dedicated Setting page, reached from the profile dropdown's
 * "Setting" item. Fields: Old / New / Confirm.
 *
 * ⚠ The session refresh below is load-bearing: AuthenticateSession compares
 * `password_hash_{guard}` against the stored hash on every request — without
 * it, changing the password force-logs the user out (the pre-2026-11 bug).
 */
class ProfileSettings extends Page implements HasForms
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static ?string $slug = 'setting';

    protected static ?string $title = 'Setting';

    protected static ?string $navigationLabel = 'Setting';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.profile-settings';

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function getHeading(): string
    {
        return __('Setting');
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
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

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('Save changes'))
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $user = Filament::auth()->user();

        // `password` is already hashed by the field's dehydrateStateUsing.
        $user->update(['password' => $data['password']]);

        if (request()->hasSession()) {
            request()->session()->put([
                'password_hash_'.Filament::getAuthGuard() => $data['password'],
            ]);
        }

        $this->form->fill();

        Notification::make()
            ->success()
            ->title(__('Password changed'))
            ->send();

        $this->redirect(PanelHome::urlFor($user));
    }
}
