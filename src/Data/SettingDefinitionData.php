<?php

declare(strict_types=1);

namespace Nvl\Settings\Data;

use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Support\Definition;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Public metadata for one source-controlled setting definition.
 */
#[TypeScript]
final class SettingDefinitionData extends Data
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $key,
        public readonly string $namespace,
        public readonly string $scope,
        public readonly SettingType $type,
        #[LiteralTypeScriptType('unknown')]
        public readonly mixed $default,
        public readonly string $description,
        public readonly int $position,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $metadata,
        public readonly string $definitionHash,
    ) {}

    /**
     * Create a public contract from one validated definition.
     */
    public static function fromDefinition(Definition $definition): self
    {
        return new self(
            key: implode('.', array_filter([
                $definition->namespace,
                $definition->scope,
                $definition->key,
            ])),
            namespace: $definition->namespace,
            scope: $definition->scope,
            type: $definition->type,
            default: $definition->default,
            description: $definition->description,
            position: $definition->position,
            metadata: $definition->metadata,
            definitionHash: $definition->hash(),
        );
    }
}
