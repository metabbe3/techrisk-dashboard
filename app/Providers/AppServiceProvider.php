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
        // 1. Force generated links to be HTTPS (Fixes Logout & Images)
    \Illuminate\Support\Facades\URL::forceScheme('https');

    // 2. TRICK Laravel into thinking the connection is Secure (Fixes 302 Loop & Upload 401)
    if(isset($this->app['request'])) {
        $this->app['request']->server->set('HTTPS', 'on');
    }
        Incident::observe(IncidentObserver::class);
        ActionImprovement::observe(ActionImprovementObserver::class);
        Label::observe(LabelObserver::class);
        IncidentType::observe(IncidentTypeObserver::class);

        FilamentView::registerRenderHook(
            'panels::body.end',
            fn (): string => view('vendor.filament.hooks.global-error-handler')->render(),
        );
    }
}
