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
use Illuminate\Support\Facades\URL;
use Filament\Support\Facades\FilamentView;

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
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
        Incident::observe(IncidentObserver::class);
        ActionImprovement::observe(ActionImprovementObserver::class);
        Label::observe(LabelObserver::class);
        IncidentType::observe(IncidentTypeObserver::class);

        FilamentView::registerRenderHook(
            'panels::body.end',
            fn (): string => view('vendor.filament.hooks.upload-error-notifier')->render(),
        );
    }
}
