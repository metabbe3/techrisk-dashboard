<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\MyIncidents;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;

class CustomProfilePage extends BaseEditProfile
{
    // Owner 2026-11-20 (revisi 3): the profile page is info + contribution
    // graph only. The change-password form lives on the dedicated Setting
    // page (App\Filament\Pages\ProfileSettings) reached from the dropdown.
    //
    // The page still extends EditProfile: the panel's ->profile() registration
    // and the dropdown's "Profile" link resolve through EditProfile's routes.
    // Its form is empty on purpose — the base mount() fills it harmlessly.
    protected static string $view = 'filament.pages.profile';

    // The panel's profile page is SIMPLE by default (Filament::isProfilePageSimple)
    // → a centered card with no topbar/sidebar. The page needs the full panel
    // layout (owner 2026-11-20).
    protected static string $layout = 'filament-panels::components.layout.index';

    /** @var array<string, mixed> */
    public array $contributionGraph = [];

    /** Year picked from the graph's year pills; defaults to the current year. */
    public int $activeYear;

    /**
     * The year pills: the user's earliest incident year through the current
     * year (owner 2026-11-21 — per-year only, no rolling mode).
     *
     * @var list<int>
     */
    public array $years = [];

    public function mount(): void
    {
        parent::mount();

        $user = $this->getUser();
        $this->years = MyIncidents::years($user);

        $requested = request()->integer('year');
        $this->activeYear = $requested > 0 ? $requested : (int) now()->year;

        $this->contributionGraph = MyIncidents::contributionGraph($user, $this->activeYear);
    }

    public function getTitle(): string
    {
        return __('Profile');
    }

    public function getHeading(): string
    {
        return __('Profile');
    }

    public function form(Form $form): Form
    {
        // Intentionally empty — see the class note above.
        return $form->schema([]);
    }
}
