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

    /** Year picked from the graph's year pills; null = rolling 12 months. */
    public ?int $activeYear = null;

    public function mount(): void
    {
        parent::mount();

        $year = request()->integer('year');
        $this->activeYear = $year > 0 ? $year : null;
        $this->contributionGraph = MyIncidents::contributionGraph($this->getUser(), $this->activeYear);
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
