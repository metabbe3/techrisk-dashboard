<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class CustomProfilePage extends BaseEditProfile
{
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->disabled(),
                TextInput::make('password')
                    ->password()
                    ->rule(Password::default())
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->dehydrated(fn ($state) => filled($state))
                    ->confirmed(),
                TextInput::make('password_confirmation')
                    ->password()
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
        return Dashboard::getUrl();
    }
}
