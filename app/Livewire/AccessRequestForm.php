<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\AccessRequest;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\Component;

class AccessRequestForm extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|email|max:255')]
    public string $email = '';

    #[Validate('nullable|string|min:8')]
    public ?string $password = '';

    #[Validate('required|integer|min:1|max:365')]
    public int $requested_duration_days = 30;

    #[Validate('required|array|min:1')]
    public array $requested_years = [];

    #[Validate('required|string|min:10|max:1000')]
    public string $reason = '';

    public bool $submitted = false;

    public ?string $submittedMessage = null;

    public function mount(): void
    {
        // Set default years to current year
        $this->requested_years = [(int) date('Y')];
    }

    public function submit(): void
    {
        $this->validate();

        // Check if email already exists as a user
        $userExists = \App\Models\User::where('email', $this->email)->exists();

        // If user exists, we don't need password
        $passwordToStore = $userExists ? null : $this->password;

        // Check if there's already a pending request for this email
        $existingRequest = AccessRequest::where('email', $this->email)
            ->where('status', 'pending')
            ->first();

        if ($existingRequest) {
            $this->addError('email', 'You already have a pending access request. Please wait for approval.');

            return;
        }

        // Create the access request
        AccessRequest::create([
            'id' => Str::uuid()->toString(),
            'name' => $this->name,
            'email' => $this->email,
            'password' => $passwordToStore,
            'requested_duration_days' => $this->requested_duration_days,
            'requested_years' => $this->requested_years,
            'reason' => $this->reason,
            'status' => 'pending',
        ]);

        $this->submitted = true;
        $this->submittedMessage = 'Your access request has been submitted successfully. You will receive an email once your request is reviewed and approved.';
    }

    public function getAvailableYearsProperty(): array
    {
        $currentYear = (int) date('Y');
        $years = [];

        for ($i = $currentYear - 2; $i <= $currentYear + 1; $i++) {
            $years[$i] = (string) $i;
        }

        return $years;
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.access-request-form')
            ->layout('layouts.public');
    }
}
