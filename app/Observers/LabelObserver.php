<?php

namespace App\Observers;

use App\Models\Label;
use Illuminate\Support\Facades\Cache;

class LabelObserver
{
    /**
     * Handle the Label "created" event.
     */
    public function created(Label $label): void
    {
        Cache::forget('labels');
    }

    /**
     * Handle the Label "updated" event.
     */
    public function updated(Label $label): void
    {
        Cache::forget('labels');
    }

    /**
     * Handle the Label "deleted" event.
     */
    public function deleted(Label $label): void
    {
        Cache::forget('labels');
    }

    /**
     * Handle the Label "restored" event.
     */
    public function restored(Label $label): void
    {
        Cache::forget('labels');
    }

    /**
     * Handle the Label "force deleted" event.
     */
    public function forceDeleted(Label $label): void
    {
        Cache::forget('labels');
    }
}
