<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage incidents');
    }

    public function view(User $user, Category $model): bool
    {
        return $user->can('manage incidents');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Category $model): bool
    {
        return true;
    }

    public function delete(User $user, Category $model): bool
    {
        return true;
    }
}
