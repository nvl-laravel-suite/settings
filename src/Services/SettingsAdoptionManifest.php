<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use InvalidArgumentException;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\DefinitionRepository;

/**
 * Validates and normalizes a bounded legacy-to-canonical Settings adoption manifest.
 *
 * @phpstan-type NormalizedManifest array{source_connection: string|null, source_table: string, key_column: string, value_column: string, expected_count: int, key_replacements: array<string, string>}
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
            'value_column',
            'expected_count',
            'key_replacements',
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
        $expectedCount = $manifest['expected_count'] ?? null;

        if (! is_int($expectedCount) || $expectedCount < 0) {
            throw new InvalidArgumentException('Settings adoption expected_count must be a non-negative integer.');
        }

        $replacements = $manifest['key_replacements'] ?? null;

        if (! is_array($replacements) || array_is_list($replacements)) {
            throw new InvalidArgumentException('Settings adoption key_replacements must be a source-to-target JSON object.');
        }

        $maximumRecords = config('settings.adoption.maximum_records', 10_000);

        if (! is_int($maximumRecords) || $maximumRecords < 1) {
            throw new InvalidArgumentException('settings.adoption.maximum_records must be a positive integer.');
        }

        if ($expectedCount > $maximumRecords || count($replacements) > $maximumRecords) {
            throw new InvalidArgumentException(
                "Settings adoption exceeds the configured {$maximumRecords} record limit.",
            );
        }

        if ($expectedCount !== count($replacements)) {
            throw new InvalidArgumentException(
                'Settings adoption expected_count must equal the number of explicit key replacements.',
            );
        }

        $normalized = [];
        $targets = [];

        foreach ($replacements as $sourceKey => $targetKey) {
            if (! is_string($sourceKey)
                || trim($sourceKey) === ''
                || strlen($sourceKey) > 255
                || ! is_string($targetKey)
                || trim($targetKey) === '') {
                throw new InvalidArgumentException('Every Settings adoption replacement must map non-empty string keys.');
            }

            $sourceKey = trim($sourceKey);
            $targetKey = trim($targetKey);
            $this->definitions->get($targetKey);

            if (isset($targets[$targetKey])) {
                throw new InvalidArgumentException(
                    "Settings adoption target key [{$targetKey}] is mapped more than once.",
                );
            }

            $normalized[$sourceKey] = $targetKey;
            $targets[$targetKey] = true;
        }

        $target = new Setting;
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
            'expected_count' => $expectedCount,
            'key_replacements' => $normalized,
        ];
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
