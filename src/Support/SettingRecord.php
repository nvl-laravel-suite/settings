<?php

declare(strict_types=1);

namespace Nvl\Settings\Support;

use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Models\Setting;

/**
 * Maps a definition and its optional row to a bounded native result.
 *
 * @phpstan-type Resolved array{key: string, value: mixed, source: string, type: SettingType, revision: int, definition_hash: string, has_override: bool, orphaned: bool, valid_from: ?string, valid_until: ?string}
 * @phpstan-type DefinitionRecord array{key: string, namespace: string, scope: string, type: SettingType, default: mixed, description: string, position: int, metadata: array<string, mixed>, definition_hash: string}
 */
final class SettingRecord
{
    /**
     * @return Resolved
     */
    public static function resolved(Definition $definition, ?Setting $setting): array
    {
        return [
            'key' => self::key($definition),
            'value' => $setting instanceof Setting ? $setting->resolved() : $definition->default,
            'source' => self::source($setting),
            'type' => $definition->type,
            'revision' => $setting instanceof Setting ? $setting->revision : 0,
            'definition_hash' => $setting instanceof Setting ? $setting->definition_hash : $definition->hash(),
            'has_override' => $setting?->isCustomised() ?? false,
            'orphaned' => $setting?->orphaned_at !== null,
            'valid_from' => $setting?->valid_from?->toAtomString(),
            'valid_until' => $setting?->valid_until?->toAtomString(),
        ];
    }

    /**
     * @return DefinitionRecord
     */
    public static function definition(Definition $definition): array
    {
        return [
            'key' => self::key($definition),
            'namespace' => $definition->namespace,
            'scope' => $definition->scope,
            'type' => $definition->type,
            'default' => $definition->default,
            'description' => $definition->description,
            'position' => $definition->position,
            'metadata' => $definition->metadata,
            'definition_hash' => $definition->hash(),
        ];
    }

    private static function key(Definition $definition): string
    {
        return implode('.', array_filter(
            [$definition->namespace, $definition->scope, $definition->key],
            static fn (string $segment): bool => $segment !== '',
        ));
    }

    /** @return 'database'|'definition' */
    private static function source(?Setting $setting): string
    {
        return $setting?->hasActiveOverride() ? 'database' : 'definition';
    }
}
