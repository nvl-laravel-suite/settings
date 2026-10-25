<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Nvl\Settings\Support\DefinitionRepository;

/**
 * Validates configured setting sources without mutating the database.
 *
 * @api
 */
final readonly class SettingsSourceValidator
{
    /**
     * Create the source-validation action.
     */
    public function __construct(private DefinitionRepository $definitions) {}

    /**
     * Return a sanitized deterministic discovery and definition status.
     *
     * @return array{valid: bool, source_count: int, definition_count: int, namespaces: list<string>, source_files: list<string>, checksum: string, error: null}
     */
    public function inspect(): array
    {
        $map = $this->definitions->refresh();
        $definitions = $this->definitions->all();
        $namespaces = array_keys($map);
        $sourceFiles = array_map(
            static fn (string $path): string => basename($path),
            array_values($map),
        );
        sort($namespaces);
        sort($sourceFiles);

        return ['valid' => true, 'source_count' => count($map), 'definition_count' => count($definitions), 'namespaces' => $namespaces, 'source_files' => $sourceFiles, 'checksum' => $this->definitions->checksum($map), 'error' => null];
    }
}
