<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ActionImprovement;
use App\Models\User;

class ActionImprovementPolicy
{
    public function viewAny(User $user): bool
    {
        // Panel users reach action improvements through the incident view
        // (Filament gates the whole relation tab on this); API users keep
        // their own permission.
        return $user->can('view incidents') || $user->can('access api');
    }

    public function view(User $user, ActionImprovement $actionImprovement): bool
    {
        return $user->can('view incidents') || $user->can('access api');
    }

    public function create(User $user): bool
    {
        return $user->can('manage incidents');
    }

    public function update(User $user, ActionImprovement $actionImprovement): bool
    {
        return $user->can('manage incidents');
    }

    public function delete(User $user, ActionImprovement $actionImprovement): bool
    {
        return $user->can('manage incidents');
    }
}
