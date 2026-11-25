<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InvestigationDocument;
use App\Models\User;

class InvestigationDocumentPolicy
{
    public function create(User $user): bool
    {
        return $user->can('manage incidents');
    }

    public function update(User $user, InvestigationDocument $document): bool
    {
        return $user->can('manage incidents');
    }

    public function delete(User $user, InvestigationDocument $document): bool
    {
        return $user->can('manage incidents');
    }
}
