<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\AccessRequest;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.public')]
class RequestAccessForm extends Component implements HasForms
{
    use InteractsWithForms;
    use WithRateLimiting;

    public array $data = [];

    public bool $submitted = false;

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
            ->schema([
                Placeholder::make('header')
                    ->label('')
                    ->content(new HtmlString('<div class="text-center mb-6">
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Request Dashboard Access</h2>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Fill out the form below to request access to the Tech Risk Dashboard.</p>
                    </div>')),

                TextInput::make('name')
                    ->label('Full Name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('John Doe')
                    ->autocomplete(false),

                TextInput::make('email')
                    ->label('Email Address')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique('access_requests', 'email', fn ($record) => $record?->where('status', 'pending'))
                    ->placeholder('john.doe@example.com')
                    ->autocomplete(),

                TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->minLength(8)
                    ->helperText('Leave blank if you already have an account')
                    ->dehydrated(fn ($state) => ! empty($state))
                    ->dehydrateStateUsing(fn ($state) => empty($state) ? null : Hash::make($state)),

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
                    ])
                    ->default(30)
                    ->selectablePlaceholder(false),

                CheckboxList::make('requested_years')
                    ->label('Data Years Required')
                    ->required()
                    ->options(function () {
                        $years = [];
                        $currentYear = (int) date('Y');
                        for ($i = $currentYear - 2; $i <= $currentYear + 1; $i++) {
                            $years[$i] = (string) $i;
                        }

                        return $years;
                    })
                    ->gridDirection('row')
                    ->columns(3),

                Textarea::make('reason')
                    ->label('Reason for Access')
                    ->required()
                    ->minLength(10)
                    ->maxLength(1000)
                    ->rows(4)
                    ->placeholder('Please explain why you need access to the dashboard and what data you will be working with...'),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        try {
            $this->rateLimit(5);

            $data = $this->form->getState();

            // Check if user already exists
            $userExists = \App\Models\User::where('email', $data['email'])->exists();

            // Validate password if user doesn't exist
            if (! $userExists && empty($data['password'])) {
                throw ValidationException::withMessages([
                    'password' => 'Password is required for new users.',
                ]);
            }

            // If user exists, we don't need password
            $passwordToStore = $userExists ? null : ($data['password'] ?? null);

            // Check if there's already a pending request for this email
            $existingRequest = AccessRequest::where('email', $data['email'])
                ->where('status', 'pending')
                ->first();

            if ($existingRequest) {
                throw ValidationException::withMessages([
                    'email' => 'You already have a pending access request. Please wait for approval.',
                ]);
            }

            // Create the access request
            AccessRequest::create([
                'id' => Str::uuid()->toString(),
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $passwordToStore,
                'requested_duration_days' => $data['requested_duration_days'],
                'requested_years' => $data['requested_years'],
                'reason' => $data['reason'],
                'status' => 'pending',
            ]);

            $this->submitted = true;

            Notification::make()
                ->title('Request Submitted')
                ->body('Your access request has been submitted successfully. You will receive an email once your request is reviewed and approved.')
                ->success()
                ->send();

        } catch (ValidationException $e) {
            throw $e;
        }
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.request-access-form');
    }
}
