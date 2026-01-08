<?php

namespace App\Providers;

use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\Label;
use App\Models\IncidentType;
use App\Observers\ActionImprovementObserver;
use App\Observers\IncidentObserver;
use App\Observers\LabelObserver;
use App\Observers\IncidentTypeObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Incident::observe(IncidentObserver::class);
        ActionImprovement::observe(ActionImprovementObserver::class);
        Label::observe(LabelObserver::class);
        IncidentType::observe(IncidentTypeObserver::class);
    }
}
