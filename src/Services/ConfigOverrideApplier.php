<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Support\Str;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\Definition;
use Nvl\Settings\Support\DefinitionRepository;

/**
 * Applies explicitly mapped effective settings to Laravel configuration.
 */
final readonly class ConfigOverrideApplier
{
    /**
     * Create the configuration override applier.
     */
    public function __construct(
        private DefinitionRepository $definitions,
        private SettingCache $cache,
    ) {}

    /**
     * Apply every allowed definition mapping, including unsynchronized defaults.
     */
    public function apply(): void
    {
        if (! (bool) config('settings.overrides.enabled', false)) {
            return;
        }

        $records = $this->cache->records()
            ->keyBy(static fn (Setting $setting): string => $setting->fullKey());

        foreach ($this->definitions->all() as $key => $definition) {
            if (! $this->mayOverride($definition)) {
                continue;
            }

            $record = $records->get($key);
            config([
                $definition->overrides => $record instanceof Setting
                    ? $record->resolved()
                    : $definition->default,
            ]);
        }
    }

    /**
     * Determine whether one definition may override its target.
     */
    private function mayOverride(Definition $definition): bool
    {
        if ($definition->overrides === null || ! config()->has($definition->overrides)) {
            return false;
        }

        $denied = config('settings.overrides.denied', []);

        foreach (is_array($denied) ? $denied : [] as $pattern) {
            if (is_string($pattern) && Str::is($pattern, $definition->overrides)) {
                return false;
            }
        }

        return true;
    }
}
