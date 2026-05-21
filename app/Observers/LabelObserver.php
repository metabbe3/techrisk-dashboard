<?php

namespace App\Observers;

use App\Models\Label;

class LabelObserver extends CacheClearingObserver
{
    protected function cacheKey(): string
    {
        return 'labels';
    }

    /**
     * Handle the Label "force deleted" event.
     */
    public function forceDeleted(Label $label): void
    {
        $this->clearCache();
    }
}
