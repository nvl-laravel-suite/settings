<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use InvalidArgumentException;
use Nvl\Settings\Support\DefinitionRepository;

/**
 * Validates and normalizes a bounded legacy-to-canonical Settings adoption manifest.
 *
 * @phpstan-type NormalizedManifest array{source_connection: string|null, source_table: string, key_column: string, value_column: string, scope_column: ?string, valid_from_column: ?string, value_encoding: 'target_codec'|'json', orphan_policy: 'reject'|'report', preserve_identity: bool, fingerprint: ?string, expected_count: int, key_replacements: array<string, string>, expected_revisions: array<string, int>}
 *
 * @internal
 */
final readonly class SettingsAdoptionManifest
{
    /**
     * Create the adoption manifest validator.
     */
    public function __construct(private DefinitionRepository $definitions) {}

    /**
     * Validate a complete manifest and all canonical target keys.
     *
     * @param  array<array-key, mixed>  $manifest
     * @return NormalizedManifest
     */
    public function normalize(array $manifest): array
    {
        $unknown = array_diff(array_keys($manifest), [
            'version',
            'source_connection',
            'source_table',
            'key_column',
            'scope_column',
            'valid_from_column',
            'value_column',
            'value_encoding',
            'orphan_policy',
            'preserve_identity',
            'fingerprint',
            'expected_count',
            'key_replacements',
            'expected_revisions',
        ]);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Settings adoption manifest contains unknown option ['.(string) reset($unknown).'].',
            );
        }

        if (($manifest['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Settings adoption manifest version must be 1.');
        }

        $connection = $manifest['source_connection'] ?? config('database.default');

        if ($connection !== null && (! is_string($connection) || trim($connection) === '')) {
            throw new InvalidArgumentException('Settings adoption source_connection must be a connection name or null.');
        }

        $sourceTable = $this->safeIdentifier($manifest['source_table'] ?? null, 'source_table');
        $keyColumn = $this->safeIdentifier($manifest['key_column'] ?? 'key', 'key_column');
        $valueColumn = $this->safeIdentifier($manifest['value_column'] ?? 'value', 'value_column');
        $scopeColumn = isset($manifest['scope_column']) ? $this->safeIdentifier($manifest['scope_column'], 'scope_column') : null;
        $validFromColumn = isset($manifest['valid_from_column']) ? $this->safeIdentifier($manifest['valid_from_column'], 'valid_from_column') : null;
        $encoding = $manifest['value_encoding'] ?? 'target_codec';
        $orphanPolicy = $manifest['orphan_policy'] ?? 'reject';
        $preserve = $manifest['preserve_identity'] ?? false;
        $fingerprint = $manifest['fingerprint'] ?? null;
        if (($encoding !== 'target_codec' && $encoding !== 'json') || ($orphanPolicy !== 'reject' && $orphanPolicy !== 'report')
            || ! is_bool($preserve) || ($fingerprint !== null && (! is_string($fingerprint) || preg_match('/^[0-9a-f]{64}$/D', $fingerprint) !== 1))) {
            throw new InvalidArgumentException('Settings adoption encoding, orphan policy, identity preservation or fingerprint is invalid.');
        }
        $columns = array_filter([$keyColumn, $valueColumn, $scopeColumn, $validFromColumn, ...($preserve ? ['id', 'created_at', 'updated_at'] : [])]);
        if (array_unique($columns) !== $columns) {
            throw new InvalidArgumentException('Settings adoption requires distinct source columns.');
        }
        $expectedCount = $manifest['expected_count'] ?? null;

        if (! is_int($expectedCount) || $expectedCount < 0) {
            throw new InvalidArgumentException('Settings adoption expected_count must be a non-negative integer.');
        }

        $replacements = $manifest['key_replacements'] ?? null;

        if (! is_array($replacements) || ($replacements !== [] && array_is_list($replacements))) {
            throw new InvalidArgumentException('Settings adoption key_replacements must be a source-to-target JSON object.');
        }

        $maximumRecords = config('nvl-settings.adoption.maximum_records', 10_000);

        if (! is_int($maximumRecords) || $maximumRecords < 1) {
            throw new InvalidArgumentException('settings.adoption.maximum_records must be a positive integer.');
        }

        if ($expectedCount > $maximumRecords) {
            throw new InvalidArgumentException(
                "Settings adoption exceeds the configured {$maximumRecords} record limit.",
            );
        }

        $normalized = $this->replacements($replacements, $scopeColumn !== null, $maximumRecords);
        if (count($normalized) > $expectedCount || ($orphanPolicy === 'reject' && $expectedCount !== count($normalized))) {
            throw new InvalidArgumentException(
                'Settings adoption expected_count must equal the number of explicit key replacements.',
            );
        }

        $targets = array_fill_keys($normalized, true);
        $revisions = $manifest['expected_revisions'] ?? [];
        if (! is_array($revisions) || ($revisions !== [] && array_is_list($revisions))) {
            throw new InvalidArgumentException('Settings adoption expected_revisions must be a target-to-revision JSON object.');
        }
        foreach ($revisions as $targetKey => $revision) {
            if (! is_string($targetKey) || ! isset($targets[$targetKey]) || ! is_int($revision) || $revision < 0) {
                throw new InvalidArgumentException('Settings adoption expected_revisions must contain mapped target keys and nonnegative revisions.');
            }
        }

        $target = SettingModelRegistry::newInstance();
        $targetConnection = $target->getConnectionName() ?? config('database.default');

        if ($sourceTable === $target->getTable() && $connection === $targetConnection) {
            throw new InvalidArgumentException(
                "Settings adoption source table [{$sourceTable}] collides with the canonical package table; rename it to a staging table first.",
            );
        }

        return [
            'source_connection' => $connection,
            'source_table' => $sourceTable,
            'key_column' => $keyColumn,
            'value_column' => $valueColumn,
            'scope_column' => $scopeColumn,
            'valid_from_column' => $validFromColumn,
            'value_encoding' => $encoding,
            'orphan_policy' => $orphanPolicy,
            'preserve_identity' => $preserve,
            'fingerprint' => $fingerprint,
            'expected_count' => $expectedCount,
            'key_replacements' => $normalized,
            'expected_revisions' => $revisions,
        ];
    }

    /**
     * @param  array<array-key,mixed>  $replacements
     * @return array<string,string>
     */
    private function replacements(array $replacements, bool $scoped, int $maximum): array
    {
        $groups = $scoped ? $replacements : ['' => $replacements];
        $normalized = $targets = [];
        foreach ($groups as $scope => $entries) {
            if (! is_string($scope) || ($scoped && (trim($scope) === '' || strlen($scope) > 255)) || ! is_array($entries)) {
                throw new InvalidArgumentException('Scoped Settings replacements must group keys by source scope.');
            }
            foreach ($entries as $sourceKey => $targetKey) {
                if (! is_string($sourceKey) || trim($sourceKey) === '' || strlen($sourceKey) > 255 || ! is_string($targetKey) || trim($targetKey) === '') {
                    throw new InvalidArgumentException('Every Settings adoption replacement must map non-empty string keys.');
                }
                $targetKey = trim($targetKey);
                $identity = self::sourceIdentity($scoped ? trim($scope) : null, trim($sourceKey));
                $this->definitions->get($targetKey);
                if (isset($targets[$targetKey])) {
                    throw new InvalidArgumentException("Settings adoption target key [{$targetKey}] is mapped more than once.");
                }
                if (isset($normalized[$identity]) || count($normalized) >= $maximum) {
                    throw new InvalidArgumentException('Settings adoption has a duplicate source identity or exceeds its record limit.');
                }
                $normalized[$identity] = $targetKey;
                $targets[$targetKey] = true;
            }
        }
        ksort($normalized);

        return $normalized;
    }

    public static function sourceIdentity(?string $scope, string $key): string
    {
        return $scope === null ? $key : json_encode([$scope, $key], JSON_THROW_ON_ERROR);
    }

    /**
     * Validate a safe SQL identifier used by the schema and query builders.
     */
    private function safeIdentifier(mixed $value, string $key): string
    {
        if (! is_string($value)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value) !== 1) {
            throw new InvalidArgumentException("Settings adoption {$key} must be a safe SQL identifier.");
        }

        return $value;
    }
}
