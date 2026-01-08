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
use Illuminate\Support\Str;

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
        if (env('APP_ENV') === 'production') {
            // Force generated links to be HTTPS (Fixes Mixed Content & Logout)
            \Illuminate\Support\Facades\URL::forceScheme('https');

            // TRICK Laravel into thinking the connection is Secure (Fixes 302 Loop & Upload 401)
            if(isset($this->app['request'])) {
                $this->app['request']->server->set('HTTPS', 'on');
            }

            // Force the asset URL if it's not already HTTPS
            if (! Str::startsWith(config('app.asset_url'), 'https://')) {
                config(['app.asset_url' => str_replace('http://', 'https://', config('app.asset_url'))]);
            }
            // Force the app URL if it's not already HTTPS
            if (! Str::startsWith(config('app.url'), 'https://')) {
                config(['app.url' => str_replace('http://', 'https://', config('app.url'))]);
            }
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
