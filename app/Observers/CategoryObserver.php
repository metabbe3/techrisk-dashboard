<?php

namespace App\Observers;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;

class CategoryObserver extends CacheClearingObserver
{
    protected function cacheKey(): string
    {
        // Abstract contract satisfies — clearCache() is fully overridden below.
        return '';
    }

    protected function clearCache(): void
    {
        Cache::forget('categories:'.Category::TYPE_BUSINESS_CATEGORY);
        Cache::forget('categories:'.Category::TYPE_ROOT_CAUSE_CATEGORY);
        Cache::forget('categories:'.Category::TYPE_RESPONSIBLE_TEAM);
    }

    /**
     * Handle the Category "force deleted" event.
     */
    public function forceDeleted(Category $category): void
    {
        $this->clearCache();
    }
}
