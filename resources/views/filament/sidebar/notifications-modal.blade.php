{{-- Mounts the database-notifications slide-over modal at page-body level
     (panels::body.end). Registered by AdminPanelProvider. Guarded on auth:
     this hook also renders on the login page (SimplePage shares the base
     layout), where Filament's component would abort(401) — its
     getNotificationsQuery() requires a user. NOT lazy: the modal markup must
     exist in the initial HTML so the bell's open-modal dispatch has something
     to open before any intersection fires (the wrapper sits at body end, so
     lazy would load it immediately anyway). --}}
@if (filament()->auth()->check())
    @livewire(\App\Filament\Livewire\DatabaseNotifications::class)
@endif
