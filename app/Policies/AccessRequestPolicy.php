<?php

namespace App\Policies;

use App\Models\AccessRequest;
use App\Models\User;

class AccessRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage users');
    }

    public function view(User $user, AccessRequest $model): bool
    {
        return $user->can('manage users');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AccessRequest $model): bool
    {
        return true;
    }

    public function delete(User $user, AccessRequest $model): bool
    {
        return true;
    }
}
