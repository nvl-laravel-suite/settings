<?php

declare(strict_types=1);

namespace Nvl\Settings\Data;

use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\Definition;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Effective runtime value plus source and concurrency information.
 */
#[TypeScript]
final class SettingValueData extends Data
{
    /**
     * Create an effective setting value contract.
     */
    public function __construct(
        public readonly string $key,
        #[LiteralTypeScriptType('unknown')]
        public readonly mixed $value,
        #[LiteralTypeScriptType("'definition' | 'database'")]
        public readonly string $source,
        public readonly SettingType $type,
        public readonly int $revision,
        public readonly string $definitionHash,
        public readonly bool $hasOverride,
        public readonly bool $orphaned,
        public readonly ?string $validFrom = null,
        public readonly ?string $validUntil = null,
    ) {}

    /**
     * Create an effective value from a persisted setting.
     */
    public static function fromModel(Setting $setting): self
    {
        return new self(
            key: $setting->fullKey(),
            value: $setting->resolved(),
            source: $setting->hasActiveOverride() ? 'database' : 'definition',
            type: $setting->type,
            revision: $setting->revision,
            definitionHash: $setting->definition_hash,
            hasOverride: $setting->isCustomised(),
            orphaned: $setting->orphaned_at !== null,
            validFrom: $setting->valid_from?->toAtomString(),
            validUntil: $setting->valid_until?->toAtomString(),
        );
    }

    /**
     * Create an effective value for a definition without a persisted row.
     */
    public static function fromDefinition(Definition $definition): self
    {
        return new self(
            key: implode('.', array_filter([
                $definition->namespace,
                $definition->scope,
                $definition->key,
            ])),
            value: $definition->default,
            source: 'definition',
            type: $definition->type,
            revision: 0,
            definitionHash: $definition->hash(),
            hasOverride: false,
            orphaned: false,
        );
    }
}
