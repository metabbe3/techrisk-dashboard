<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;

trait RestrictsToApiAccess
{
    public function viewAny(User $user): bool
    {
        return $user->can('access api');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('access api');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, $model): bool
    {
        return false;
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
