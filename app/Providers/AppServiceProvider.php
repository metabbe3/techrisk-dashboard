<?php

namespace App\Providers;

use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\Label;
use App\Models\IncidentType;
use App\Models\StatusUpdate;
use App\Observers\ActionImprovementObserver;
use App\Observers\IncidentObserver;
use App\Observers\LabelObserver;
use App\Observers\IncidentTypeObserver;
use App\Observers\StatusUpdateObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Route;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Str;
use Livewire\Livewire;

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
        // CHANGE THIS LINE: Use $this->app->environment()
        // You can also add 'prod' or 'staging' if your PAAS uses those names
        if ($this->app->environment(['production', 'prod', 'staging'])) {
            
            // 1. Force HTTPS Scheme
            URL::forceScheme('https');

            // 2. Fix the Request Server variables for Proxies
            if(isset($this->app['request'])) {
                $this->app['request']->server->set('HTTPS', 'on');
            }

            // 3. Update Configs dynamically
            $urlConfig = config('app.url');
            if ($urlConfig && ! Str::startsWith($urlConfig, 'https://')) {
                config(['app.url' => str_replace('http://', 'https://', $urlConfig)]);
            }

            $assetConfig = config('app.asset_url');
            if ($assetConfig && ! Str::startsWith($assetConfig, 'https://')) {
                config(['app.asset_url' => str_replace('http://', 'https://', $assetConfig)]);
            }
        }

        Incident::observe(IncidentObserver::class);
        ActionImprovement::observe(ActionImprovementObserver::class);
        StatusUpdate::observe(StatusUpdateObserver::class);
        Label::observe(LabelObserver::class);
        IncidentType::observe(IncidentTypeObserver::class);

        FilamentView::registerRenderHook(
            'panels::body.end',
            fn (): string => view('vendor.filament.hooks.global-error-handler')->render(),
        );

        // Configure Livewire to use web middleware for session/auth
        if (class_exists(Livewire::class)) {
            Livewire::setUpdateRoute(function ($handle) {
                return Route::post('/livewire/update', $handle)
                    ->middleware('web')
                    ->name('livewire.update');
            });
        }
    }
}
