<?php

declare(strict_types=1);
namespace App\Filament\Resources\IssueResource\Pages;

use App\Filament\Resources\IssueResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIssue extends CreateRecord
{
    protected static string $resource = IssueResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
