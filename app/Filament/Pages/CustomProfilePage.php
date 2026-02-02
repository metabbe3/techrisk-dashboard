<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;
use Illuminate\Support\Facades\Hash;

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

    public function save(): void
    {
        $data = $this->form->getState();

        $this->handleRecordUpdate($this->getUser(), $data);

        $this->getSavedNotification()?->send();

        $this->redirect(Dashboard::getUrl());
    }
}
