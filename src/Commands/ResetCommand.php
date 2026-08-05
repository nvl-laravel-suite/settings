<?php

declare(strict_types=1);

namespace Nvl\Settings\Commands;

use Illuminate\Console\Command;
use Nvl\Settings\Actions\GetManySettingsAction;
use Nvl\Settings\Actions\ResetSettingAction;
use Nvl\Settings\Data\SettingValueData;
use Nvl\Settings\Support\DefinitionRepository;

/**
 * Safely resets one setting or an explicitly confirmed namespace/scope group.
 */
final class ResetCommand extends Command
{
    protected $signature = 'nvl:settings:reset
        {pattern : An exact key, namespace, or namespace.scope}
        {--dry-run : Report matching overrides without changing them}
        {--force : Confirm resetting more than one matching override}';

    protected $description = 'Reset matching settings to their fallback values';

    /**
     * Reset matched overrides through the canonical optimistic Action boundary.
     */
    public function handle(
        DefinitionRepository $definitions,
        GetManySettingsAction $getMany,
        ResetSettingAction $reset,
    ): int {
        $pattern = (string) $this->argument('pattern');
        $matches = array_values(array_filter(
            array_keys($definitions->all()),
            static fn (string $key): bool => $key === $pattern
                || str_starts_with($key, $pattern.'.'),
        ));

        if ($matches === []) {
            $this->error("No setting definitions match [{$pattern}].");

            return self::FAILURE;
        }

        if (count($matches) > 1 && $this->option('force') !== true) {
            $this->error('Multiple settings match; rerun with --force or use an exact key.');

            return self::FAILURE;
        }

        $overrides = array_values(array_filter(
            $getMany->execute($matches),
            static fn (SettingValueData $value): bool => $value->hasOverride,
        ));

        if ($this->option('dry-run') === true) {
            $this->info('[Dry Run] Would reset '.count($overrides).' setting overrides.');

            return self::SUCCESS;
        }

        foreach ($overrides as $override) {
            $reset->execute($override->key, $override->revision);
        }

        $this->info('Reset '.count($overrides).' settings to their default values.');

        return self::SUCCESS;
    }
}
