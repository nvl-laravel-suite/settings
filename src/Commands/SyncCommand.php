<?php

declare(strict_types=1);

namespace Nvl\Settings\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Validation\ValidationException;
use Nvl\Settings\Enums\SettingPruneStrategy;
use Nvl\Settings\Exceptions\SettingException;
use Nvl\Settings\Services\SettingSynchronizer;
use Nvl\Settings\Support\Definition;
use Nvl\Settings\Support\DefinitionRepository;
use Throwable;

/**
 * Console adapter for previewing and committing definition synchronization.
 */
final class SyncCommand extends Command implements Isolatable
{
    protected $signature = 'nvl:settings:sync {--dry-run} {--provider=} {--prune}';

    protected $description = 'Sync settings definitions to the database';

    /**
     * Synchronize definitions and optionally orphan or delete removed keys.
     */
    public function handle(
        DefinitionRepository $repository,
        SettingSynchronizer $synchronizer,
    ): int {
        $dryRun = $this->option('dry-run') === true;
        $providerOption = $this->option('provider');
        $provider = is_string($providerOption) ? $providerOption : null;
        $configuredPrune = config('settings.sync.prune', SettingPruneStrategy::Orphan->value);
        $prune = $this->option('prune') === true
            ? SettingPruneStrategy::Delete
            : (is_string($configuredPrune)
                ? SettingPruneStrategy::tryFrom($configuredPrune)
                : null);
        $respectDatabaseValues = config('settings.sync.respect_db_values', true) === true;

        if (! $prune instanceof SettingPruneStrategy) {
            $value = is_scalar($configuredPrune) ? (string) $configuredPrune : get_debug_type($configuredPrune);
            $this->error("Unsupported settings prune strategy [{$value}].");

            return self::FAILURE;
        }

        try {
            $map = $repository->refresh();
            $definitions = $this->definitionsForProvider($repository->all(), $provider);
        } catch (SettingException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Found '.count($definitions).' definitions across '.count($map).' namespaces.');

        if ($dryRun) {
            try {
                $result = $synchronizer->preview($definitions, $provider, $prune);
            } catch (Throwable $throwable) {
                $this->error($throwable->getMessage());

                return self::FAILURE;
            }

            foreach ($result->failures as $failure) {
                $this->error($failure);
            }

            $this->info("[Dry Run] Would synchronize {$result->synchronized} settings.");

            if ($result->orphans > 0) {
                $this->info(
                    "[Dry Run] Would {$prune->value} {$result->orphans} orphaned settings.",
                );
            }

            return $result->isValid() ? self::SUCCESS : self::FAILURE;
        }

        try {
            $result = $synchronizer->synchronize(
                $definitions,
                $provider,
                $prune,
                $respectDatabaseValues,
            );
        } catch (ValidationException $exception) {
            $this->error($this->firstValidationError($exception));

            return self::FAILURE;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info("Synchronized {$result->synchronized} settings.");

        if ($result->orphans > 0) {
            $this->info(
                ucfirst($prune->value)."d {$result->orphans} orphaned settings.",
            );
        }

        return self::SUCCESS;
    }

    /**
     * Filter definitions to one optional provider namespace.
     *
     * @param  array<string, Definition>  $definitions
     * @return array<string, Definition>
     */
    private function definitionsForProvider(array $definitions, ?string $provider): array
    {
        if ($provider === null) {
            return $definitions;
        }

        return array_filter(
            $definitions,
            static fn (Definition $definition): bool => $definition->namespace === $provider,
        );
    }

    /**
     * Return the first string validation error.
     */
    private function firstValidationError(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                if (is_string($message)) {
                    return $message;
                }
            }
        }

        return $exception->getMessage();
    }
}
