<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PanelHome;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel root serves the Dashboard. Users without widget access
 * (team-viewers today) would dead-end in a 403 there — route them to their
 * panel home (the incidents list) instead. Users who can see neither the
 * dashboard nor incidents keep the 403 (unchanged pre-existing behavior).
 * PanelHome::urlFor is the ONE landing rule — login and this middleware agree.
 */
class RedirectToPanelHome
{
    public function handle(Request $request, Closure $next): Response
    {
        $panel = Filament::getCurrentPanel();
        $user = $request->user();

        if ($panel !== null
            && $user !== null
            && $request->path() === $panel->getPath()
            && ! $user->canViewDashboardWidgets()
            && $user->can('view incidents')) {
            return redirect()->to(PanelHome::urlFor($user));
        }

        return $next($request);
    }
}
