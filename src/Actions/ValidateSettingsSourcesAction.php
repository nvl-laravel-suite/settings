<?php

declare(strict_types=1);

namespace Nvl\Settings\Actions;

use Nvl\Settings\Data\SettingsSourceStatusData;
use Nvl\Settings\Support\DefinitionRepository;

/**
 * Validates configured setting sources without mutating the database.
 */
final readonly class ValidateSettingsSourcesAction
{
    /**
     * Create the source-validation action.
     */
    public function __construct(private DefinitionRepository $definitions) {}

    /**
     * Return a sanitized deterministic discovery and definition status.
     */
    public function execute(): SettingsSourceStatusData
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

        return new SettingsSourceStatusData(
            valid: true,
            sourceCount: count($map),
            definitionCount: count($definitions),
            namespaces: $namespaces,
            sourceFiles: $sourceFiles,
            checksum: $this->definitions->checksum($map),
            error: null,
        );
    }
}
