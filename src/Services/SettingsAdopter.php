<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use RuntimeException;

/**
 * Plans and applies a reconciled import from one staged legacy settings table.
 *
 * @api
 *
 * @phpstan-import-type NormalizedManifest from SettingsAdoptionManifest
 *
 * @phpstan-type Prepared array{value:mixed, source:array{id:string,created_at:?string,updated_at:?string}|null, valid_from:?DateTimeImmutable, verify_window:bool}
 * @phpstan-type AdoptionResult array{mode:string,source_table:string,schema_fingerprint:string,fingerprint:string,orphans:list<array{scope:?string,key:string}>,conflicts:list<string>,reconciliation:array{expected:int,source:int,mapped:int,matched:int,created:int,updated:int,unchanged:int}}
 */
final readonly class SettingsAdopter
{
    /**
     * Create the Settings adoption action.
     */
    public function __construct(
        private SettingsAdoptionManifest $manifests,
        private DefinitionRepository $definitions,
        private SettingValueValidator $values,
        private SettingWriter $writer,
        private OwnershipBoundary $boundary,
    ) {}

    /**
     * Validate every source row and optionally persist all mapped overrides atomically.
     *
     * @param  array<array-key, mixed>  $manifest
     * @return AdoptionResult
     */
    public function execute(array $manifest, bool $apply = false, Authenticatable|SystemActor|null $actor = null): array
    {
        return $this->run($manifest, $apply ? 'apply' : 'plan', $actor);
    }

    /**
     * Reconcile the committed target against a reviewed fingerprint before cutover.
     *
     * @param  array<array-key, mixed>  $manifest
     * @return AdoptionResult
     */
    public function verify(array $manifest): array
    {
        if (SettingModelRegistry::newInstance()->getConnection()->transactionLevel() !== 0) {
            throw new InvalidArgumentException('Settings adoption verification requires a committed target outside a transaction.');
        }

        return $this->run($manifest, 'verify', null);
    }

    /**
     * @param  array<array-key,mixed>  $manifest
     * @param  'plan'|'apply'|'verify'  $mode
     * @return AdoptionResult
     */
    private function run(array $manifest, string $mode, Authenticatable|SystemActor|null $actor): array
    {
        $normalized = $this->manifests->normalize($manifest);
        $connection = $normalized['source_connection'];
        $table = $normalized['source_table'];
        $schema = Schema::connection($connection);
        $scopeColumn = $normalized['scope_column'];
        $validFromColumn = $normalized['valid_from_column'];
        $columns = [$normalized['key_column'], $normalized['value_column']];
        if ($scopeColumn !== null) {
            $columns[] = $scopeColumn;
        }
        if ($validFromColumn !== null) {
            $columns[] = $validFromColumn;
        }
        if ($normalized['preserve_identity']) {
            array_push($columns, 'id', 'created_at', 'updated_at');
        }

        if (! $schema->hasTable($table)) {
            throw new InvalidArgumentException("Settings adoption source table [{$table}] does not exist.");
        }

        $origin = DB::connection($connection);
        $discovered = $schema->getColumns($table);
        if (array_diff($columns, array_column($discovered, 'name')) !== [] || ($normalized['preserve_identity'] && ! $schema->hasIndex($table, ['id'], 'primary'))) {
            throw new InvalidArgumentException("Settings adoption source table [{$table}] has an incompatible schema.");
        }

        $target = SettingModelRegistry::newInstance();
        $this->assertCanonicalSchema();
        $targetSchema = $target->getConnection()->getSchemaBuilder();
        $schemaFingerprint = hash('sha256', json_encode([
            $origin->getName(), $origin->getDatabaseName(), $origin->getTablePrefix().$table, $discovered, $schema->getIndexes($table), $schema->getForeignKeys($table),
            $target->getConnection()->getName(), $target->getConnection()->getDatabaseName(), $target->getConnection()->getTablePrefix().$target->getTable(),
            $targetSchema->getColumns($target->getTable()), $targetSchema->getIndexes($target->getTable()),
        ], JSON_THROW_ON_ERROR));
        $query = $origin->table($table)->useWritePdo()->select($columns);
        if ($scopeColumn !== null) {
            $query->orderBy($scopeColumn);
        }
        $rows = $query->orderBy($normalized['key_column'])->limit($normalized['expected_count'] + 1)->get();

        if ($rows->count() !== $normalized['expected_count']) {
            throw new InvalidArgumentException(
                "Settings adoption expected {$normalized['expected_count']} source rows but found "
                .($rows->count() > $normalized['expected_count'] ? 'at least ' : '').$rows->count().'.',
            );
        }

        $mappedSources = $prepared = $orphans = $conflicts = [];
        $options = array_diff_key($normalized, array_fill_keys(['fingerprint', 'expected_revisions'], true));
        $digest = hash_init('sha256');
        hash_update($digest, $schemaFingerprint.json_encode($options, JSON_THROW_ON_ERROR));

        foreach ($rows as $row) {
            hash_update($digest, json_encode($row, JSON_THROW_ON_ERROR)."\n");
            $sourceKey = $row->{$normalized['key_column']} ?? null;
            $sourceScope = $scopeColumn === null ? null : ($row->{$scopeColumn} ?? null);
            if (! is_string($sourceKey) || trim($sourceKey) === '' || strlen($sourceKey) > 255
                || ($scopeColumn !== null && (! is_string($sourceScope) || trim($sourceScope) === '' || strlen($sourceScope) > 255))) {
                throw new InvalidArgumentException('Settings adoption found a source row with an invalid key.');
            }

            $sourceKey = trim($sourceKey);
            $sourceScope = is_string($sourceScope) ? trim($sourceScope) : null;
            $identity = SettingsAdoptionManifest::sourceIdentity($sourceScope, $sourceKey);
            if (isset($mappedSources[$identity])) {
                throw new InvalidArgumentException("Settings adoption source key [{$identity}] is duplicated.");
            }
            $mappedSources[$identity] = true;

            if (! array_key_exists($identity, $normalized['key_replacements'])) {
                if ($normalized['orphan_policy'] === 'reject') {
                    throw new InvalidArgumentException("Settings adoption source key [{$identity}] has no explicit replacement.");
                }
                $orphans[] = ['scope' => $sourceScope, 'key' => $sourceKey];

                continue;
            }

            $targetKey = $normalized['key_replacements'][$identity];
            $definition = $this->definitions->get($targetKey);
            hash_update($digest, $definition->hash());
            $value = $this->decode($row->{$normalized['value_column']} ?? null, $definition->type, $normalized['value_encoding'], $identity);
            $this->values->validate($definition, $value, $targetKey);
            $source = null;
            if ($normalized['preserve_identity']) {
                $id = $row->id ?? null;
                if (! is_string($id) || ! Str::isUuid($id) || strtolower($id) !== $id) {
                    throw new InvalidArgumentException("Settings adoption source [{$identity}] requires a canonical UUID.");
                }
                $source = [
                    'id' => $id,
                    'created_at' => $this->timestamp($row->created_at ?? null)?->format('Y-m-d H:i:s'),
                    'updated_at' => $this->timestamp($row->updated_at ?? null)?->format('Y-m-d H:i:s'),
                ];
            }
            $prepared[$targetKey] = [
                'value' => $value, 'source' => $source,
                'valid_from' => $validFromColumn === null ? null : $this->timestamp($row->{$validFromColumn} ?? null),
                'verify_window' => $source !== null || $validFromColumn !== null,
            ];
        }

        $missingSources = array_diff_key($normalized['key_replacements'], $mappedSources);

        if ($missingSources !== []) {
            throw new InvalidArgumentException(
                'Settings adoption replacements reference missing source key ['.(string) array_key_first($missingSources).'].',
            );
        }

        $fingerprint = hash_final($digest);
        $requiresFingerprint = $mode === 'verify' || $scopeColumn !== null || $validFromColumn !== null
            || $normalized['preserve_identity'] || $normalized['value_encoding'] !== 'target_codec' || $normalized['orphan_policy'] !== 'reject';
        if ($mode !== 'plan' && ($requiresFingerprint || $normalized['fingerprint'] !== null)
            && ! hash_equals($fingerprint, $normalized['fingerprint'] ?? '')) {
            throw new InvalidArgumentException('A matching reviewed Settings adoption fingerprint is required.');
        }

        [$created, $updated, $unchanged, $conflicts] = $this->plannedWrites($prepared);
        if ($mode !== 'plan' && $conflicts !== []) {
            throw new InvalidArgumentException($conflicts[0]);
        }
        $matched = 0;
        if ($mode === 'apply') {
            if ($actor === null || count($normalized['expected_revisions']) !== count($prepared)
                || array_diff(array_keys($prepared), array_keys($normalized['expected_revisions'])) !== []) {
                throw new InvalidArgumentException('Settings adoption apply requires an explicit actor and expected revision for every mapped target.');
            }
            $matched = $target->getConnection()->transaction(function () use ($actor, $normalized, $prepared): int {
                foreach ($prepared as $targetKey => $row) {
                    $revision = $normalized['expected_revisions'][$targetKey];
                    if ($row['source'] !== null) {
                        $this->writer->stageLegacyOverride($targetKey, $row['value'], $actor, $revision, $row['source'], $row['valid_from']);
                    } else {
                        $this->writer->set($targetKey, $row['value'], $actor, $revision, $row['verify_window'] ? ['valid_from' => $row['valid_from'], 'valid_until' => null] : []);
                    }
                }

                return $this->reconcile($prepared);
            });
        } elseif ($mode === 'verify') {
            $matched = $this->reconcile($prepared);
        }

        return [
            'mode' => $mode,
            'source_table' => $table,
            'schema_fingerprint' => $schemaFingerprint,
            'fingerprint' => $fingerprint,
            'orphans' => $orphans,
            'conflicts' => $conflicts,
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

    private function decode(mixed $raw, SettingType $type, string $encoding, string $identity): mixed
    {
        if ($encoding === 'json') {
            if ($raw === null) {
                return null;
            }
            if (! is_string($raw)) {
                throw new InvalidArgumentException("Settings adoption source value [{$identity}] requires stored JSON text.");
            }
            try {
                $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException("Settings adoption source value [{$identity}] is invalid JSON.", previous: $exception);
            }

            return $type === SettingType::Decimal && (is_float($decoded) || is_int($decoded)) ? trim($raw) : $decoded;
        }
        if (! is_scalar($raw) && $raw !== null && ! is_array($raw)) {
            throw new InvalidArgumentException("Settings adoption source value [{$identity}] has an unsupported database type.");
        }

        return is_string($raw) || $raw === null ? $type->deserialize($raw) : $raw;
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $timestamp = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC')) : false;
        if ($timestamp === false || $timestamp->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Settings adoption timestamps must represent whole seconds in UTC.');
        }

        return $timestamp;
    }

    /**
     * Count the expected create, update, and no-op outcomes before mutation.
     *
     * @param  array<string, Prepared>  $prepared
     * @return array{int, int, int, list<string>}
     */
    private function plannedWrites(array $prepared): array
    {
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $conflicts = [];

        foreach ($prepared as $targetKey => $row) {
            $record = $this->targetRecord($targetKey);
            if (! $record instanceof Setting) {
                if ($row['source'] !== null && $this->boundary->query(SettingModelRegistry::query(), 'settings.values')->whereKey($row['source']['id'])->exists()) {
                    $conflicts[] = "Settings adoption UUID [{$row['source']['id']}] is already assigned to another target setting.";
                }
                $created++;

                continue;
            }

            if ($this->matches($targetKey, $record, $row)) {
                $unchanged++;
            } elseif ($row['source'] !== null) {
                $conflicts[] = "Settings adoption target [{$targetKey}] conflicts with its staged legacy identity or value.";
            } else {
                $updated++;
            }
        }

        return [$created, $updated, $unchanged, $conflicts];
    }

    /**
     * Fail loudly when the configured target is a same-name legacy schema.
     */
    private function assertCanonicalSchema(): void
    {
        $model = SettingModelRegistry::newInstance();
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
     * @param  array<string, Prepared>  $prepared
     */
    private function reconcile(array $prepared): int
    {
        $matched = 0;

        foreach ($prepared as $targetKey => $row) {
            $record = $this->targetRecord($targetKey);
            if ($record instanceof Setting && $this->matches($targetKey, $record, $row)) {
                $matched++;
            }
        }
        if ($matched !== count($prepared)) {
            throw new RuntimeException("Settings adoption reconciliation matched {$matched} of ".count($prepared).' target rows.');
        }

        return $matched;
    }

    private function targetRecord(string $key): ?Setting
    {
        $definition = $this->definitions->get($key);

        return $this->boundary->query(SettingModelRegistry::query(), 'settings.values')->where([
            'namespace' => $definition->namespace, 'scope' => $definition->scope, 'key' => $definition->key,
        ])->first();
    }

    /** @param Prepared $row */
    private function matches(string $key, Setting $record, array $row): bool
    {
        $definition = $this->definitions->get($key);
        $source = $row['source'];

        return $record->has_override
            && $record->type === $definition->type
            && $definition->type->serialize($record->value) === $definition->type->serialize($row['value'])
            && ($source === null || ($record->id === $source['id'] && $record->definition_hash === $definition->hash()
                && $record->getRawOriginal('created_at') === $source['created_at'] && $record->getRawOriginal('updated_at') === $source['updated_at']))
            && (! $row['verify_window'] || ($record->valid_from?->format('Y-m-d H:i:s') === $row['valid_from']?->format('Y-m-d H:i:s') && $record->valid_until === null));
    }
}
