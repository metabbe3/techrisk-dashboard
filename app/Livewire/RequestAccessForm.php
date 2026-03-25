<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\AccessRequest;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.public')]
class RequestAccessForm extends Component
{
    use WithRateLimiting;

    public string $name = '';

    public string $email = '';

    public ?string $password = null;

    public int $requested_duration_days = 30;

    public array $requested_years = [];

    public string $reason = '';

    public bool $submitted = false;

    public function mount(): void
    {
        $this->requested_years = [(int) date('Y')];
    }

    public function submit(): void
    {
        $this->validate();

        try {
            $this->rateLimit(5);

            // Check if user already exists
            $userExists = \App\Models\User::where('email', $this->email)->exists();

            // Validate password if user doesn't exist
            if (! $userExists && empty($this->password)) {
                throw ValidationException::withMessages([
                    'password' => 'Password is required for new users.',
                ]);
            }

            // If user exists, we don't need password
            $passwordToStore = $userExists ? null : (empty($this->password) ? null : Hash::make($this->password));

            // Check if there's already a pending request for this email
            $existingRequest = AccessRequest::where('email', $this->email)
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
                'name' => $this->name,
                'email' => $this->email,
                'password' => $passwordToStore,
                'requested_duration_days' => $this->requested_duration_days,
                'requested_years' => $this->requested_years,
                'reason' => $this->reason,
                'status' => 'pending',
            ]);

            $this->submitted = true;

        } catch (ValidationException $e) {
            throw $e;
        }
    }

    public function getAvailableYearsProperty(): array
    {
        $years = [];
        $currentYear = (int) date('Y');
        for ($i = $currentYear - 2; $i <= $currentYear + 1; $i++) {
            $years[$i] = (string) $i;
        }

        return $years;
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:access_requests,email,NULL,id,EXCEPT NULL',
            'password' => 'nullable|string|min:8',
            'requested_duration_days' => 'required|integer|min:1|max:365',
            'requested_years' => 'required|array|min:1',
            'requested_years.*' => 'integer',
            'reason' => 'required|string|min:10|max:1000',
        ];
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.request-access-form');
    }
}
