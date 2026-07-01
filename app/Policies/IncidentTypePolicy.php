<?php

namespace App\Policies;

use App\Models\IncidentType;
use App\Models\User;

class IncidentTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view incident types');
    }

    public function view(User $user, IncidentType $model): bool
    {
        return $user->can('view incident types');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, IncidentType $model): bool
    {
        return true;
    }

    public function delete(User $user, IncidentType $model): bool
    {
        return true;
    }
}
