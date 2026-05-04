<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\AccessRequest;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.public')]
class RequestAccessForm extends Component implements HasForms
{
    use InteractsWithForms;
    use WithRateLimiting;

    public ?array $data = [];

    public bool $submitted = false;

    public string $honeypot = '';

    public function mount(): void
    {
        $this->form->fill([
            'requested_duration_days' => 30,
            'requested_years' => [(int) date('Y')],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                TextInput::make('name')
                    ->label('Full Name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('John Doe'),

                TextInput::make('email')
                    ->label('Email Address')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->placeholder('john.doe@example.com'),

                TextInput::make('password')
                    ->label('Password')
                    ->helperText('Leave blank if you already have an account')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->placeholder('Min. 8 characters'),

                Select::make('requested_duration_days')
                    ->label('Access Duration')
                    ->required()
                    ->options([
                        7 => '7 days',
                        14 => '14 days',
                        30 => '30 days (1 month)',
                        60 => '60 days (2 months)',
                        90 => '90 days (3 months)',
                        180 => '180 days (6 months)',
                        365 => '365 days (1 year)',
                    ]),

                CheckboxList::make('requested_years')
                    ->label('Data Years Required')
                    ->required()
                    ->minItems(1)
                    ->options(fn () => collect(range((int) date('Y') - 2, (int) date('Y') + 1))
                        ->mapWithKeys(fn ($year) => [$year => (string) $year]))
                    ->gridDirection('row')
                    ->columns(3),

                Textarea::make('reason')
                    ->label('Reason for Access')
                    ->required()
                    ->minLength(10)
                    ->maxLength(1000)
                    ->rows(4)
                    ->placeholder('Please explain why you need access to the dashboard and what data you will be working with...'),
            ]);
    }

    public function submit(): void
    {
        $formData = $this->form->getState();

        // Honeypot check: bots will fill this hidden field
        if (! empty($this->honeypot)) {
            $this->submitted = true;

            return;
        }

        $this->rateLimit(5);

        $userExists = \App\Models\User::where('email', $formData['email'])->exists();

        if (! $userExists && empty($formData['password'])) {
            throw ValidationException::withMessages([
                'data.password' => 'Password is required for new users.',
            ]);
        }

        $passwordToStore = $userExists ? null : (empty($formData['password']) ? null : Hash::make($formData['password']));

        $existingRequest = AccessRequest::where('email', $formData['email'])
            ->where('status', 'pending')
            ->first();

        if ($existingRequest) {
            throw ValidationException::withMessages([
                'data.email' => 'You already have a pending access request. Please wait for approval.',
            ]);
        }

        AccessRequest::create([
            'id' => Str::uuid()->toString(),
            'name' => $formData['name'],
            'email' => $formData['email'],
            'password' => $passwordToStore,
            'requested_duration_days' => (int) $formData['requested_duration_days'],
            'requested_years' => $formData['requested_years'],
            'reason' => $formData['reason'],
            'status' => 'pending',
        ]);

        $this->submitted = true;
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.request-access-form');
    }
}
