<?php

declare(strict_types=1);

namespace Nvl\Settings\Testing;

use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Services\SettingCache;
use Nvl\Settings\Support\DefinitionRepository;

/**
 * Provides deterministic in-memory definitions for package consumers' tests.
 */
trait InteractsWithSettings
{
    /**
     * Replace registered setting definitions for the current test.
     *
     * @param  array<string, array{type: SettingType, default?: mixed, description?: string, rules?: array<int, mixed>, position?: int, overrides?: string|null, metadata?: array<string, mixed>}>  $definitions
     */
    public function defineSettings(array $definitions): void
    {
        app(DefinitionRepository::class)->fake($definitions);
        app(SettingCache::class)->flush();
    }
}
