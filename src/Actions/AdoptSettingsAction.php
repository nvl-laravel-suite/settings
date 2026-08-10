<?php

declare(strict_types=1);

namespace Nvl\Settings\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\SettingsAdoptionManifest;
use Nvl\Settings\Services\SettingValueValidator;
use Nvl\Settings\Support\DefinitionRepository;
use RuntimeException;

/**
 * Plans and applies a reconciled import from one staged legacy settings table.
 */
final readonly class AdoptSettingsAction
{
    /**
     * Create the Settings adoption action.
     */
    public function __construct(
        private SettingsAdoptionManifest $manifests,
        private DefinitionRepository $definitions,
        private SettingValueValidator $values,
        private SettingRepository $settings,
    ) {}

    /**
     * Validate every source row and optionally persist all mapped overrides atomically.
     *
     * @param  array<array-key, mixed>  $manifest
     * @return array{mode: string, source_table: string, reconciliation: array{expected: int, source: int, mapped: int, matched: int, created: int, updated: int, unchanged: int}}
     */
    public function execute(array $manifest, bool $apply = false): array
    {
        $normalized = $this->manifests->normalize($manifest);
        $connection = $normalized['source_connection'];
        $table = $normalized['source_table'];
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($table)) {
            throw new InvalidArgumentException("Settings adoption source table [{$table}] does not exist.");
        }

        foreach ([$normalized['key_column'], $normalized['value_column']] as $column) {
            if (! $schema->hasColumn($table, $column)) {
                throw new InvalidArgumentException(
                    "Settings adoption source table [{$table}] is missing column [{$column}].",
                );
            }
        }

        $rows = DB::connection($connection)
            ->table($table)
            ->select([$normalized['key_column'], $normalized['value_column']])
            ->orderBy($normalized['key_column'])
            ->get();

        if ($rows->count() !== $normalized['expected_count']) {
            throw new InvalidArgumentException(
                "Settings adoption expected {$normalized['expected_count']} source rows but found {$rows->count()}.",
            );
        }

        $mappedSources = [];
        $prepared = [];

        foreach ($rows as $row) {
            $sourceKey = $row->{$normalized['key_column']} ?? null;

            if (! is_string($sourceKey) || trim($sourceKey) === '') {
                throw new InvalidArgumentException('Settings adoption found a source row with an invalid key.');
            }

            $sourceKey = trim($sourceKey);

            if (isset($mappedSources[$sourceKey])) {
                throw new InvalidArgumentException("Settings adoption source key [{$sourceKey}] is duplicated.");
            }

            if (! array_key_exists($sourceKey, $normalized['key_replacements'])) {
                throw new InvalidArgumentException("Settings adoption source key [{$sourceKey}] has no explicit replacement.");
            }

            $targetKey = $normalized['key_replacements'][$sourceKey];
            $definition = $this->definitions->get($targetKey);
            $rawValue = $row->{$normalized['value_column']} ?? null;

            if (! is_scalar($rawValue) && $rawValue !== null && ! is_array($rawValue)) {
                throw new InvalidArgumentException(
                    "Settings adoption source value [{$sourceKey}] has an unsupported database type.",
                );
            }

            $value = is_string($rawValue) || $rawValue === null
                ? $definition->type->deserialize($rawValue)
                : $rawValue;
            $this->values->validate($definition, $value, $targetKey);
            $prepared[$targetKey] = $value;
            $mappedSources[$sourceKey] = true;
        }

        $missingSources = array_diff_key($normalized['key_replacements'], $mappedSources);

        if ($missingSources !== []) {
            throw new InvalidArgumentException(
                'Settings adoption replacements reference missing source key ['.(string) array_key_first($missingSources).'].',
            );
        }

        [$created, $updated, $unchanged] = $this->plannedWrites($prepared);

        if ($apply) {
            $this->assertCanonicalSchema();
            $this->settings->setMany($prepared);
            $matched = $this->reconcile($prepared);

            if ($matched !== count($prepared)) {
                throw new RuntimeException(
                    "Settings adoption reconciliation matched {$matched} of ".count($prepared).' target rows.',
                );
            }
        } else {
            $matched = 0;
        }

        return [
            'mode' => $apply ? 'apply' : 'plan',
            'source_table' => $table,
            'reconciliation' => [
                'expected' => $normalized['expected_count'],
                'source' => $rows->count(),
                'mapped' => count($prepared),
                'matched' => $matched,
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
            ],
        ];
    }

    /**
     * Count the expected create, update, and no-op outcomes before mutation.
     *
     * @param  array<string, mixed>  $prepared
     * @return array{int, int, int}
     */
    private function plannedWrites(array $prepared): array
    {
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($prepared as $targetKey => $value) {
            $definition = $this->definitions->get($targetKey);
            $record = Setting::query()->where([
                'namespace' => $definition->namespace,
                'scope' => $definition->scope,
                'key' => $definition->key,
            ])->first();

            if (! $record instanceof Setting) {
                $created++;

                continue;
            }

            if ($record->has_override
                && $definition->type->serialize($record->value) === $definition->type->serialize($value)) {
                $unchanged++;
            } else {
                $updated++;
            }
        }

        return [$created, $updated, $unchanged];
    }

    /**
     * Fail loudly when the configured target is a same-name legacy schema.
     */
    private function assertCanonicalSchema(): void
    {
        $model = new Setting;
        $schema = Schema::connection($model->getConnectionName());
        $table = $model->getTable();
        $required = [
            'id', 'namespace', 'scope', 'key', 'type', 'value', 'fallback',
            'has_override', 'definition_hash', 'revision', 'metadata', 'valid_from',
            'valid_until', 'synced_at', 'orphaned_at', 'created_at', 'updated_at',
        ];

        if (! $schema->hasTable($table)) {
            throw new InvalidArgumentException("Canonical Settings table [{$table}] does not exist.");
        }

        $missing = array_values(array_filter(
            $required,
            static fn (string $column): bool => ! $schema->hasColumn($table, $column),
        ));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                "Configured Settings table [{$table}] is not the canonical package schema; missing columns: ".implode(', ', $missing).'.',
            );
        }
    }

    /**
     * Verify every target row contains the requested canonical override.
     *
     * @param  array<string, mixed>  $prepared
     */
    private function reconcile(array $prepared): int
    {
        $matched = 0;

        foreach ($prepared as $targetKey => $value) {
            $definition = $this->definitions->get($targetKey);
            $record = Setting::query()->where([
                'namespace' => $definition->namespace,
                'scope' => $definition->scope,
                'key' => $definition->key,
            ])->first();

            if ($record instanceof Setting
                && $record->has_override
                && $definition->type->serialize($record->value) === $definition->type->serialize($value)) {
                $matched++;
            }
        }

        return $matched;
    }
}
