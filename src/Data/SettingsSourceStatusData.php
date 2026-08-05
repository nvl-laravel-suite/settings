<?php

declare(strict_types=1);

namespace Nvl\Settings\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Reports a sanitized source-discovery validation result.
 */
#[TypeScript]
final class SettingsSourceStatusData extends Data
{
    /**
     * @param  list<string>  $namespaces
     * @param  list<string>  $sourceFiles
     */
    public function __construct(
        public readonly bool $valid,
        public readonly int $sourceCount,
        public readonly int $definitionCount,
        public readonly array $namespaces,
        public readonly array $sourceFiles,
        public readonly ?string $checksum,
        public readonly ?string $error,
    ) {}
}
