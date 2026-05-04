<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ActionImprovement;
use App\Models\User;

class ActionImprovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('access api');
    }

    public function view(User $user, ActionImprovement $actionImprovement): bool
    {
        return $user->can('access api');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ActionImprovement $actionImprovement): bool
    {
        return false;
    }

    public function delete(User $user, ActionImprovement $actionImprovement): bool
    {
        return false;
    }
}
