<?php

declare(strict_types=1);

namespace Nvl\Settings\Actions;

use Nvl\Settings\Data\SettingValueData;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Tenancy\Services\TenantBoundary;

/**
 * Resolves one defined setting with explicit source metadata.
 */
final readonly class GetSettingAction
{
    /**
     * Create the single-setting read action.
     */
    public function __construct(
        private DefinitionRepository $definitions,
        private TenantBoundary $boundary,
    ) {}

    /**
     * Resolve one definition against its optional persisted record.
     */
    public function execute(string $key): SettingValueData
    {
        $definition = $this->definitions->get($key);
        $setting = $this->boundary->query(Setting::query(), 'settings.values')->where([
            'namespace' => $definition->namespace,
            'scope' => $definition->scope,
            'key' => $definition->key,
        ])->first();

        if ($setting instanceof Setting) {
            return SettingValueData::fromModel($setting);
        }

        return SettingValueData::fromDefinition($definition);
    }
}
