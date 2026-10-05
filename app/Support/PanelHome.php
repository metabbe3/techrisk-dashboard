<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Single source for the panel landing URL: users with `manage incidents`
 * land on the Dashboard, everyone else on the incidents list (the Dashboard
 * page's canAccess() gate requires that permission — sending a user-role
 * account there is a guaranteed 403). Login and the profile page must agree.
 */
class PanelHome
{
    public static function urlFor(?Authenticatable $user): string
    {
        return $user?->can('manage incidents')
            ? route('filament.admin.pages.dashboard')
            : route('filament.admin.resources.incidents.index');
    }
}
