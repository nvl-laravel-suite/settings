<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Data\SettingsDoctorCheckData;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Support\SettingsRouteConfiguration;
use Throwable;

/**
 * Inspects Settings schema, definitions, caches, and management configuration without mutation.
 */
final readonly class SettingsDoctor
{
    /**
     * Create the Settings readiness inspector.
     */
    public function __construct(
        private DefinitionRepository $definitions,
        private SettingsAuthorization $authorization,
    ) {}

    /**
     * Return every Settings readiness check.
     *
     * @return list<SettingsDoctorCheckData>
     */
    public function inspect(): array
    {
        return [
            ...$this->schemaChecks(),
            $this->definitionCheck(),
            $this->discoveryCacheCheck(),
            $this->managementRouteCheck(),
        ];
    }

    /**
     * Inspect required columns, key type, indexes, and duplicate identities.
     *
     * @return list<SettingsDoctorCheckData>
     */
    private function schemaChecks(): array
    {
        $model = new Setting;
        $schema = Schema::connection($model->getConnectionName());
        $table = $model->getTable();

        try {
            $exists = $schema->hasTable($table);
        } catch (Throwable $exception) {
            return [new SettingsDoctorCheckData(
                key: 'schema.connection',
                severity: 'error',
                passed: false,
                message: 'The configured Settings database is unavailable: '.$exception->getMessage(),
            )];
        }

        if (! $exists) {
            return [new SettingsDoctorCheckData(
                key: 'schema.table',
                severity: 'error',
                passed: false,
                message: "Table [{$table}] is missing.",
            )];
        }

        $required = [
            'id', 'namespace', 'scope', 'key', 'type', 'value', 'fallback',
            'has_override', 'definition_hash', 'revision', 'metadata', 'valid_from',
            'valid_until', 'synced_at', 'orphaned_at', 'created_at', 'updated_at',
        ];
        $missing = array_values(array_filter(
            $required,
            static fn (string $column): bool => ! $schema->hasColumn($table, $column),
        ));
        $checks = [
            new SettingsDoctorCheckData(
                key: 'schema.table',
                severity: 'error',
                passed: true,
                message: "Table [{$table}] exists.",
            ),
            new SettingsDoctorCheckData(
                key: 'schema.compatibility',
                severity: 'error',
                passed: $missing === [],
                message: $missing === []
                    ? "Table [{$table}] matches the canonical NVL Settings v1 schema."
                    : "Table [{$table}] exists but is not the NVL Settings v1 schema; treat it as a legacy name collision and rename it to a staging table before migration or adoption.",
            ),
            new SettingsDoctorCheckData(
                key: 'schema.columns',
                severity: 'error',
                passed: $missing === [],
                message: $missing === []
                    ? 'Required v1 columns exist.'
                    : 'Missing columns: '.implode(', ', $missing).'.',
            ),
        ];

        if ($missing !== []) {
            return $checks;
        }

        $idType = $schema->getColumnType($table, 'id');
        $checks[] = new SettingsDoctorCheckData(
            key: 'schema.id-type',
            severity: 'error',
            passed: in_array($idType, ['uuid', 'guid', 'char', 'varchar', 'string'], true),
            message: "Settings identifier column uses [{$idType}].",
        );
        $indexes = collect($schema->getIndexes($table));
        $requiredIndexes = [
            'identity' => [
                'columns' => ['namespace', 'scope', 'key'],
                'unique' => true,
            ],
            'namespace-scope' => [
                'columns' => ['namespace', 'scope'],
                'unique' => false,
            ],
            'sync-status' => [
                'columns' => ['orphaned_at', 'synced_at'],
                'unique' => false,
            ],
            'validity' => [
                'columns' => ['valid_from', 'valid_until'],
                'unique' => false,
            ],
        ];

        foreach ($requiredIndexes as $key => $requirement) {
            $index = $indexes->first(
                static fn (array $index): bool => $index['columns']
                    === $requirement['columns']
                    && $index['unique'] === $requirement['unique'],
            );
            $checks[] = new SettingsDoctorCheckData(
                key: "schema.index.{$key}",
                severity: 'error',
                passed: is_array($index),
                message: is_array($index)
                    ? "Index [{$index['name']}] covers the required {$key} columns."
                    : "The required {$key} index is missing.",
            );
        }

        $duplicatesExist = Setting::query()
            ->select(['namespace', 'scope', 'key'])
            ->groupBy(['namespace', 'scope', 'key'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $checks[] = new SettingsDoctorCheckData(
            key: 'schema.duplicate-identities',
            severity: 'error',
            passed: ! $duplicatesExist,
            message: ! $duplicatesExist
                ? 'No duplicate setting identities exist.'
                : 'Duplicate setting identities must be resolved.',
        );
        $query = DB::connection($model->getConnectionName())->table($table);
        $invalidRowsExist = (clone $query)
            ->where(static function (Builder $query): void {
                $query
                    ->whereNull('namespace')
                    ->orWhere('namespace', '')
                    ->orWhereNull('key')
                    ->orWhere('key', '')
                    ->orWhereRaw('LENGTH(namespace) > 100')
                    ->orWhereRaw('LENGTH(scope) > 100')
                    ->orWhereRaw('LENGTH(key) > 100')
                    ->orWhere('revision', '<', 1)
                    ->orWhereNull('definition_hash')
                    ->orWhereRaw('LENGTH(definition_hash) <> 64')
                    ->orWhere(static function (Builder $query): void {
                        $query
                            ->where('has_override', false)
                            ->whereNotNull('value');
                    });
            })
            ->orWhereNotIn(
                'type',
                array_map(
                    static fn (SettingType $type): string => $type->value,
                    SettingType::cases(),
                ),
            )
            ->exists();
        $checks[] = new SettingsDoctorCheckData(
            key: 'schema.row-integrity',
            severity: 'error',
            passed: ! $invalidRowsExist,
            message: $invalidRowsExist
                ? 'One or more setting rows have invalid identity, type, revision, or definition hashes.'
                : 'Persisted setting identities, types, revisions, and hashes are valid.',
        );
        $invalidCodec = false;
        $invalidIdentifier = false;

        foreach ((clone $query)->select([
            'namespace',
            'scope',
            'key',
            'type',
            'value',
            'has_override',
            'fallback',
        ])->orderBy('id')->cursor() as $record) {
            try {
                $namespace = $record->namespace;
                $scope = $record->scope;
                $key = $record->key;
                $typeValue = $record->type;
                $fallback = $record->fallback;
                $value = $record->value;

                if (! is_string($namespace)
                    || ! is_string($scope)
                    || ! is_string($key)
                    || preg_match('/^[A-Za-z0-9_-]+$/', $namespace) !== 1
                    || ($scope !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $scope) !== 1)
                    || preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
                    $invalidIdentifier = true;
                }
                if (! is_string($typeValue)) {
                    throw new InvalidArgumentException('Stored type must be a string.');
                }
                if (! is_string($fallback) && $fallback !== null) {
                    throw new InvalidArgumentException('Stored fallback must be a string or null.');
                }
                if (! is_string($value) && $value !== null) {
                    throw new InvalidArgumentException('Stored value must be a string or null.');
                }

                $type = SettingType::from($typeValue);
                $decodedFallback = $type->deserialize($fallback);

                if ($type->serialize($decodedFallback) !== $fallback) {
                    throw new InvalidArgumentException('Stored fallback is not canonical.');
                }

                if ((bool) $record->has_override) {
                    $decodedValue = $type->deserialize($value);

                    if ($type->serialize($decodedValue) !== $value) {
                        throw new InvalidArgumentException('Stored override is not canonical.');
                    }
                }
            } catch (Throwable) {
                $invalidCodec = true;
            }
        }
        $checks[] = new SettingsDoctorCheckData(
            key: 'schema.value-codec',
            severity: 'error',
            passed: ! $invalidCodec,
            message: $invalidCodec
                ? 'One or more stored values are not canonical for their declared type.'
                : 'Stored fallbacks and overrides use canonical type encoding.',
        );
        $checks[] = new SettingsDoctorCheckData(
            key: 'schema.identifiers',
            severity: 'error',
            passed: ! $invalidIdentifier,
            message: $invalidIdentifier
                ? 'One or more persisted identity segments contain unsafe characters.'
                : 'Persisted identity segments use the canonical character set.',
        );
        $invalidWindowsExist = (clone $query)
            ->whereNotNull('valid_from')
            ->whereNotNull('valid_until')
            ->whereColumn('valid_until', '<=', 'valid_from')
            ->exists();
        $checks[] = new SettingsDoctorCheckData(
            key: 'schema.validity-windows',
            severity: 'error',
            passed: ! $invalidWindowsExist,
            message: $invalidWindowsExist
                ? 'One or more setting validity windows end before they start.'
                : 'Persisted setting validity windows are ordered correctly.',
        );

        return $checks;
    }

    /**
     * Validate every configured source definition.
     */
    private function definitionCheck(): SettingsDoctorCheckData
    {
        try {
            $this->definitions->refresh();
            $count = count($this->definitions->all());

            return new SettingsDoctorCheckData(
                key: 'definitions',
                severity: 'error',
                passed: true,
                message: "{$count} source definitions are valid.",
            );
        } catch (Throwable $exception) {
            return new SettingsDoctorCheckData(
                key: 'definitions',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }
    }

    /**
     * Validate the optional bootstrap discovery cache.
     */
    private function discoveryCacheCheck(): SettingsDoctorCheckData
    {
        if (! (bool) config('settings.discovery.cache', true)) {
            return new SettingsDoctorCheckData(
                key: 'definitions.cache',
                severity: 'warning',
                passed: true,
                message: 'Definition discovery caching is disabled.',
            );
        }

        try {
            $path = $this->definitions->cachePath();
            $fresh = $this->definitions->refresh();
            $runtime = $this->definitions->map();
        } catch (Throwable $exception) {
            return new SettingsDoctorCheckData(
                key: 'definitions.cache',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }

        $current = $fresh === $runtime;

        return new SettingsDoctorCheckData(
            key: 'definitions.cache',
            severity: 'warning',
            passed: (! file_exists($path) || is_readable($path)) && $current,
            message: ! $current
                ? 'Definition cache is stale; rebuild it with nvl:settings:cache.'
                : (file_exists($path)
                    ? "Definition cache [{$path}] is readable and current."
                    : 'Definition cache is not built; discovery will scan configured roots.'),
        );
    }

    /**
     * Validate disabled-by-default management routing and authorization.
     */
    private function managementRouteCheck(): SettingsDoctorCheckData
    {
        if (! (bool) config('settings.management.enabled', false)) {
            return new SettingsDoctorCheckData(
                key: 'management.routes',
                severity: 'warning',
                passed: true,
                message: 'Management routes are disabled.',
            );
        }

        try {
            $routeName = SettingsRouteConfiguration::name().'index';
            SettingsRouteConfiguration::path();
        } catch (InvalidArgumentException $exception) {
            return new SettingsDoctorCheckData(
                key: 'management.routes',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }

        $ability = config('settings.management.authorization_ability');
        $authorizationConfigured = ! $this->authorization instanceof ConfiguredSettingsAuthorization
            || (is_string($ability) && $ability !== '');
        $middleware = array_values(array_filter(
            (array) config('settings.management.middleware', []),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));
        $healthy = $authorizationConfigured
            && $middleware !== []
            && Route::has($routeName);

        return new SettingsDoctorCheckData(
            key: 'management.routes',
            severity: 'error',
            passed: $healthy,
            message: match (true) {
                ! $authorizationConfigured => 'Management routes require consumer authorization.',
                $middleware === [] => 'Management routes require middleware.',
                ! Route::has($routeName) => 'Management routes are enabled but were not registered.',
                default => 'Management routes are enabled and secured.',
            },
        );
    }
}
