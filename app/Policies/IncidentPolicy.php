<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('access api');
    }

    public function view(User $user, Incident $incident): bool
    {
        return $user->can('access api');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Incident $incident): bool
    {
        return false;
    }

    public function delete(User $user, Incident $incident): bool
    {
        return false;
    }
}
