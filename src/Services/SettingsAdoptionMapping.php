<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
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
 * Reads, validates and maps staged legacy settings rows for both the atomic adopter and the checkpointed adapter.
 *
 * @internal
 *
 * @phpstan-import-type NormalizedManifest from SettingsAdoptionManifest
 *
 * @phpstan-type Prepared array{value:mixed, source:array{id:string,created_at:?string,updated_at:?string}|null, valid_from:?DateTimeImmutable, verify_window:bool}
 * @phpstan-type Source array{manifest: NormalizedManifest, origin: Connection, columns: list<string>, schema_fingerprint: string}
 * @phpstan-type Scan array{count: int, prepared: array<string, Prepared>, orphans: list<array{scope:?string,key:string}>, fingerprint: string}
 */
final readonly class SettingsAdoptionMapping
{
    public function __construct(
        private SettingsAdoptionManifest $manifests,
        private DefinitionRepository $definitions,
        private SettingValueValidator $values,
        private SettingWriter $writer,
        private OwnershipBoundary $boundary,
    ) {}

    /**
     * Normalize the manifest and check the staged source and canonical target schemas.
     *
     * @param  array<array-key, mixed>  $manifest
     * @return Source
     */
    public function source(array $manifest): array
    {
        $normalized = $this->manifests->normalize($manifest);
        $connection = $normalized['source_connection'];
        $table = $normalized['source_table'];
        $schema = Schema::connection($connection);
        $columns = [$normalized['key_column'], $normalized['value_column']];
        if ($normalized['scope_column'] !== null) {
            $columns[] = $normalized['scope_column'];
        }
        if ($normalized['valid_from_column'] !== null) {
            $columns[] = $normalized['valid_from_column'];
        }
        if ($normalized['preserve_identity']) {
            array_push($columns, 'id', 'created_at', 'updated_at');
        }

        if (! $schema->hasTable($table)) {
            throw new InvalidArgumentException("Settings adoption source table [{$table}] does not exist.");
        }

        /** @var Connection $origin */
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

        return ['manifest' => $normalized, 'origin' => $origin, 'columns' => $columns, 'schema_fingerprint' => $schemaFingerprint];
    }

    /**
     * Return an unordered query over the staged source columns on the write connection.
     *
     * @param  Source  $source
     */
    public function query(array $source): Builder
    {
        return $source['origin']->table($source['manifest']['source_table'])->useWritePdo()->select($source['columns']);
    }

    /**
     * Read, validate and map every source row, and hash it into the reviewed source fingerprint.
     *
     * @param  Source  $source
     * @return Scan
     */
    public function scan(array $source): array
    {
        $normalized = $source['manifest'];
        $query = $this->query($source);
        if ($normalized['scope_column'] !== null) {
            $query->orderBy($normalized['scope_column']);
        }
        $rows = $query->orderBy($normalized['key_column'])->limit($normalized['expected_count'] + 1)->get();

        if ($rows->count() !== $normalized['expected_count']) {
            throw new InvalidArgumentException(
                "Settings adoption expected {$normalized['expected_count']} source rows but found "
                .($rows->count() > $normalized['expected_count'] ? 'at least ' : '').$rows->count().'.',
            );
        }

        $mappedSources = $prepared = $orphans = [];
        $options = array_diff_key($normalized, array_fill_keys(['fingerprint', 'expected_revisions'], true));
        $digest = hash_init('sha256');
        hash_update($digest, $source['schema_fingerprint'].json_encode($options, JSON_THROW_ON_ERROR));

        foreach ($rows as $row) {
            hash_update($digest, json_encode($row, JSON_THROW_ON_ERROR)."\n");
            $identity = $this->identify($source, $row);
            if (isset($mappedSources[$identity['identity']])) {
                throw new InvalidArgumentException("Settings adoption source key [{$identity['identity']}] is duplicated.");
            }
            $mappedSources[$identity['identity']] = true;

            $mapped = $this->map($source, $identity['identity'], $row);
            if ($mapped === null) {
                $orphans[] = ['scope' => $identity['scope'], 'key' => $identity['key']];

                continue;
            }
            hash_update($digest, $mapped['definition_hash']);
            $prepared[$mapped['target']] = $mapped['row'];
        }

        $missingSources = array_diff_key($normalized['key_replacements'], $mappedSources);

        if ($missingSources !== []) {
            throw new InvalidArgumentException(
                'Settings adoption replacements reference missing source key ['.(string) array_key_first($missingSources).'].',
            );
        }

        return ['count' => $rows->count(), 'prepared' => $prepared, 'orphans' => $orphans, 'fingerprint' => hash_final($digest)];
    }

    /**
     * Validate one source row's scope and key and return its trimmed source identity.
     *
     * @param  Source  $source
     * @return array{scope: ?string, key: string, identity: string}
     */
    public function identify(array $source, object $row): array
    {
        $normalized = $source['manifest'];
        $scopeColumn = $normalized['scope_column'];
        $sourceKey = $row->{$normalized['key_column']} ?? null;
        $sourceScope = $scopeColumn === null ? null : ($row->{$scopeColumn} ?? null);
        if (! is_string($sourceKey) || trim($sourceKey) === '' || strlen($sourceKey) > 255
            || ($scopeColumn !== null && (! is_string($sourceScope) || trim($sourceScope) === '' || strlen($sourceScope) > 255))) {
            throw new InvalidArgumentException('Settings adoption found a source row with an invalid key.');
        }

        $sourceKey = trim($sourceKey);
        $sourceScope = is_string($sourceScope) ? trim($sourceScope) : null;

        return ['scope' => $sourceScope, 'key' => $sourceKey, 'identity' => SettingsAdoptionManifest::sourceIdentity($sourceScope, $sourceKey)];
    }

    /**
     * Map one identified source row to its validated target override, or return null for a reported orphan.
     *
     * @param  Source  $source
     * @return array{target: string, definition_hash: string, row: Prepared}|null
     */
    public function map(array $source, string $identity, object $row): ?array
    {
        $normalized = $source['manifest'];
        if (! array_key_exists($identity, $normalized['key_replacements'])) {
            if ($normalized['orphan_policy'] === 'reject') {
                throw new InvalidArgumentException("Settings adoption source key [{$identity}] has no explicit replacement.");
            }

            return null;
        }

        $targetKey = $normalized['key_replacements'][$identity];
        $definition = $this->definitions->get($targetKey);
        $value = $this->decode($row->{$normalized['value_column']} ?? null, $definition->type, $normalized['value_encoding'], $identity);
        $this->values->validate($definition, $value, $targetKey);
        $sourceIdentity = null;
        if ($normalized['preserve_identity']) {
            $id = $row->id ?? null;
            if (! is_string($id) || ! Str::isUuid($id) || strtolower($id) !== $id) {
                throw new InvalidArgumentException("Settings adoption source [{$identity}] requires a canonical UUID.");
            }
            $sourceIdentity = [
                'id' => $id,
                'created_at' => $this->timestamp($row->created_at ?? null)?->format('Y-m-d H:i:s'),
                'updated_at' => $this->timestamp($row->updated_at ?? null)?->format('Y-m-d H:i:s'),
            ];
        }
        $validFromColumn = $normalized['valid_from_column'];

        return ['target' => $targetKey, 'definition_hash' => $definition->hash(), 'row' => [
            'value' => $value, 'source' => $sourceIdentity,
            'valid_from' => $validFromColumn === null ? null : $this->timestamp($row->{$validFromColumn} ?? null),
            'verify_window' => $sourceIdentity !== null || $validFromColumn !== null,
        ]];
    }

    /**
     * Write one mapped override through the canonical writer at its reviewed expected revision.
     *
     * @param  Prepared  $row
     */
    public function write(string $targetKey, array $row, Authenticatable|SystemActor $actor, int $expectedRevision): void
    {
        if ($row['source'] !== null) {
            $this->writer->stageLegacyOverride($targetKey, $row['value'], $actor, $expectedRevision, $row['source'], $row['valid_from']);
        } else {
            $this->writer->set($targetKey, $row['value'], $actor, $expectedRevision, $row['verify_window'] ? ['valid_from' => $row['valid_from'], 'valid_until' => null] : []);
        }
    }

    /**
     * Count the expected create, update, and no-op outcomes before mutation.
     *
     * @param  array<string, Prepared>  $prepared
     * @return array{int, int, int, list<string>}
     */
    public function plannedWrites(array $prepared): array
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
     * Verify every target row contains the requested canonical override.
     *
     * @param  array<string, Prepared>  $prepared
     */
    public function reconcile(array $prepared): int
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

    /**
     * Read the canonical row of one target setting inside the ownership boundary.
     */
    public function targetRecord(string $key): ?Setting
    {
        $definition = $this->definitions->get($key);

        return $this->boundary->query(SettingModelRegistry::query(), 'settings.values')->where([
            'namespace' => $definition->namespace, 'scope' => $definition->scope, 'key' => $definition->key,
        ])->first();
    }

    /**
     * Whether a canonical row already holds the mapped override, identity and validity window.
     *
     * @param  Prepared  $row
     */
    public function matches(string $key, Setting $record, array $row): bool
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

    /**
     * Parse a whole-second UTC source timestamp.
     */
    public function timestamp(mixed $value): ?DateTimeImmutable
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
}
