<?php

declare(strict_types=1);

namespace Nvl\Settings\Observers;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\SettingCache;

/**
 * Invalidates persisted setting records only after their transaction commits.
 */
final readonly class SettingCacheObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Create the settings cache observer.
     */
    public function __construct(private SettingCache $cache) {}

    /**
     * Invalidate cached records after a setting is saved.
     */
    public function saved(Setting $setting): void
    {
        $this->cache->flush();
    }

    /**
     * Invalidate cached records after a setting is deleted.
     */
    public function deleted(Setting $setting): void
    {
        $this->cache->flush();
    }
}
