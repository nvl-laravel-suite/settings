<?php

declare(strict_types=1);

namespace Nvl\Settings\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Combines one source definition with its effective runtime value.
 */
#[TypeScript]
final class SettingManagementData extends Data
{
    /**
     * Create one management-list item.
     */
    public function __construct(
        public readonly SettingDefinitionData $definition,
        public readonly SettingValueData $value,
    ) {}
}
