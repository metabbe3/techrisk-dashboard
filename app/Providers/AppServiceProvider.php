<?php

namespace App\Providers;

use App\Events\IncidentCreatedEvent;
use App\Events\IncidentEscalatedEvent;
use App\Listeners\Ai\AnalyzeNewIncident;
use App\Listeners\CheckTokenInactivity;
use App\Models\ActionImprovement;
use App\Models\ApiSetting;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Label;
use App\Models\StatusUpdate;
use App\Observers\ActionImprovementObserver;
use App\Observers\CategoryObserver;
use App\Observers\IncidentObserver;
use App\Observers\IncidentTypeObserver;
use App\Observers\LabelObserver;
use App\Observers\RagDocumentObserver;
use App\Observers\StatusUpdateObserver;
use App\Policies\ActionImprovementPolicy;
use App\Policies\IncidentPolicy;
use App\Services\SensitiveDataFilter;
use App\Services\TraceIdService;
use Carbon\Carbon;
use Filament\Support\Facades\FilamentView;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TraceIdService::class);
        $this->app->singleton(SensitiveDataFilter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        // Netcore Cloud email transport — registered as the 'netcore' mailer.
        Mail::extend('netcore', function (array $config) {
            return new \App\Mail\Transports\NetcoreTransport(
                apiKey: $config['api_key'] ?? '',
                baseUrl: $config['base_url'] ?? 'https://emailapi.netcorecloud.net',
                timeout: (int) ($config['timeout'] ?? 30),
            );
        });

        // Serialize all dates in the app timezone (Asia/Jakarta, GMT+7) with the
        // offset, instead of Carbon's default UTC "…Z". This makes API JSON
        // datetime output match the Filament dashboard (which formats in app tz).
        // Dashboard display is unaffected — Filament formats dates via ->format(),
        // not via JSON serialization.
        Carbon::serializeUsing(fn ($date) => $date->setTimezone(config('app.timezone', 'Asia/Jakarta'))->toIso8601String());

        // CHANGE THIS LINE: Use $this->app->environment()
        // You can also add 'prod' or 'staging' if your PAAS uses those names
        if ($this->app->environment(['production', 'prod', 'staging'])) {

            // 1. Force HTTPS Scheme
            URL::forceScheme('https');

            // 2. Fix the Request Server variables for Proxies
            if (isset($this->app['request'])) {
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

        // Listen for Sanctum token authentication to check inactivity
        Event::listen(
            \Laravel\Sanctum\Events\TokenAuthenticated::class,
            CheckTokenInactivity::class,
        );

        // AI perception events
        Event::listen(IncidentCreatedEvent::class, AnalyzeNewIncident::class);
        Event::listen(IncidentEscalatedEvent::class, AnalyzeNewIncident::class);

        Incident::observe(IncidentObserver::class);
        Incident::observe(RagDocumentObserver::class);
        ActionImprovement::observe(ActionImprovementObserver::class);
        StatusUpdate::observe(StatusUpdateObserver::class);
        Label::observe(LabelObserver::class);
        IncidentType::observe(IncidentTypeObserver::class);
        Category::observe(CategoryObserver::class);

        Gate::policy(Incident::class, IncidentPolicy::class);
        Gate::policy(ActionImprovement::class, ActionImprovementPolicy::class);

        FilamentView::registerRenderHook(
            'panels::body.end',
            fn (): string => view('vendor.filament.hooks.global-error-handler')->render(),
        ); // Only applies to Filament admin pages, not public pages

        // Configure Livewire to use web middleware for session/auth
        if (class_exists(Livewire::class)) {
            Livewire::setUpdateRoute(function ($handle) {
                return Route::post('/livewire/update', $handle)
                    ->middleware('web')
                    ->name('livewire.update');
            });
        }

        // API rate limiters — per-user per-minute limits, admin-adjustable from
        // the Filament "API Settings" page (ApiSetting store). Defaults match the
        // previous hardcoded values; the value is read live (cached briefly).
        $limitBy = fn ($request) => $request->user()?->id ?: $request->ip();

        RateLimiter::for('incidents', fn ($request) => Limit::perMinute((int) ApiSetting::get('rate_limit.incidents', 100))->by($limitBy($request)));
        RateLimiter::for('reference', fn ($request) => Limit::perMinute((int) ApiSetting::get('rate_limit.reference', 30))->by($limitBy($request)));
        RateLimiter::for('actions', fn ($request) => Limit::perMinute((int) ApiSetting::get('rate_limit.actions', 60))->by($limitBy($request)));
        RateLimiter::for('ai_export', fn ($request) => Limit::perMinute((int) ApiSetting::get('rate_limit.ai_export', 60))->by($limitBy($request)));
    }
}
