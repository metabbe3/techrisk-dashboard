<?php

namespace App\Observers;

use Illuminate\Support\Facades\Cache;

abstract class CacheClearingObserver
{
    abstract protected function cacheKey(): string;

    protected function clearCache(): void
    {
        Cache::forget($this->cacheKey());
    }

    public function created($model): void
    {
        $this->clearCache();
    }

    public function updated($model): void
    {
        $this->clearCache();
    }

    public function deleted($model): void
    {
        $this->clearCache();
    }

    public function restored($model): void
    {
        $this->clearCache();
    }
}
