<?php

namespace App\Observers;

use App\Models\User;

class UserCacheObserver extends CacheClearingObserver
{
    protected function cacheKey(): string
    {
        return 'web_search_pic_names';
    }

    public function forceDeleted(User $user): void
    {
        $this->clearCache();
    }
}
