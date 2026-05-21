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

    protected function handleRecordCreation(array $data): Model
    {
        $user = User::findOrFail($data['tokenable_id']);

        $allowedEndpoints = $data['allowed_endpoints'] ?? null;
        $expiresAt = $data['expires_at'] ?? now()->addMonths(6);
        $renewalMinutes = $data['renewal_minutes'] ?? 43200;
        $abilities = $data['abilities'] ?? ['*'];
        $tokenName = $data['name'];

        unset($data['allowed_endpoints'], $data['expires_at'], $data['renewal_minutes']);

        $newToken = $user->createToken($tokenName, $abilities);
        $this->plainTextToken = $newToken->plainTextToken;

        $token = $newToken->accessToken;
        $token->forceFill([
            'expires_at' => $expiresAt,
            'renewal_minutes' => $renewalMinutes,
            'allowed_endpoints' => ! empty($allowedEndpoints) ? $allowedEndpoints : null,
        ])->save();

        session(['api_token_plain_'.$token->id => $newToken->plainTextToken]);

        return $token;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Token created — copy it below';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getFormActions(): array
    {
        if ($this->plainTextToken) {
            return [];
        }

        return parent::getFormActions();
    }

    public function getViewData(): array
    {
        return [
            'plainTextToken' => $this->plainTextToken,
        ];
    }
}
