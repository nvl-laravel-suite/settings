<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\Definition;
use Nvl\Settings\Support\DefinitionRepository;

/**
 * Projects allowlisted platform setting values into Laravel configuration.
 *
 * @internal
 */
final readonly class PlatformConfigWriter
{
    /**
     * Create the platform configuration writer.
     */
    public function __construct(private DefinitionRepository $definitions) {}

    /**
     * Apply every allowed definition mapping, including unsynchronized defaults.
     *
     * @param  Collection<int, Setting>  $records
     */
    public function apply(Collection $records): void
    {
        $records = $records->keyBy(static fn (Setting $setting): string => $setting->fullKey());

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
