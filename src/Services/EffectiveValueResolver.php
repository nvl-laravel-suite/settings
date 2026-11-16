<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\Settings\Enums\SettingFallbackPolicy;
use Nvl\Settings\Exceptions\UnknownSettingException;
use Nvl\Settings\Support\DefinitionRepository;

/**
 * Opt-in runtime lookup with host configuration fallbacks.
 *
 * @api
 */
final readonly class EffectiveValueResolver
{
    public function __construct(
        private DefinitionRepository $definitions,
        private SettingCache $cache,
        private Repository $config,
    ) {}

    /** Read an active override, then config, then the fallback selected by the caller. */
    public function get(string $key, mixed $default = null, SettingFallbackPolicy $policy = SettingFallbackPolicy::CallerDefault): mixed
    {
        try {
            $definition = $this->definitions->get($key);
        } catch (UnknownSettingException) {
            return $this->config->get($key, $default);
        }

        $record = $this->cache->record($key);
        if ($record?->hasActiveOverride()) {
            return $record->value;
        }

        $fallback = $policy === SettingFallbackPolicy::DefinitionDefault ? $definition->default : $default;

        return $this->config->get($definition->configKey ?? $key, $fallback);
    }
}
