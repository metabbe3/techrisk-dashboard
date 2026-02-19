<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApiTokenResource\Pages;

use App\Filament\Resources\ApiTokenResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateApiToken extends CreateRecord
{
    protected static string $resource = ApiTokenResource::class;

    public ?string $plainTextToken = null;

    /**
     * Override create to capture the plain token before it's hashed
     */
    protected function handleRecordCreation(array $data): Model
    {
        // Get the user
        $user = User::findOrFail($data['tokenable_id']);

        // Prepare endpoint access
        $allowedEndpoints = $data['allowed_endpoints'] ?? null;
        unset($data['allowed_endpoints']);

        // Create the token using Sanctum
        $abilities = $data['abilities'] ?? ['*'];
        $tokenName = $data['name'];

        // Store the plain text token
        $this->plainTextToken = $user->createToken($tokenName, $abilities)->plainTextToken;

        // Get the created token model
        $token = $user->tokens()->where('name', $tokenName)->latest()->first();

        // Update with endpoint access
        if ($allowedEndpoints !== null) {
            $token->forceFill([
                'allowed_endpoints' => ! empty($allowedEndpoints) ? $allowedEndpoints : null,
            ])->save();
        }

        return $token;
    }

    /**
     * Redirect to the view page to show the token
     */
    protected function getRedirectUrl(): string
    {
        // Store the plain text token in session for display
        session(['api_token_plain_'.$this->record->id => $this->plainTextToken]);

        return ApiTokenResource::getUrl('view', ['record' => $this->record]);
    }
}
