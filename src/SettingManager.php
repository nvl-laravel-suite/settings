<?php

declare(strict_types=1);

namespace Nvl\Settings;

use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Exceptions\UnknownSettingException;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\SettingCache;
use Nvl\Settings\Support\DefinitionRepository;

/** Read-only runtime settings access for configuration and bootstrap consumers. */
final readonly class SettingManager implements SettingRepository
{
    public function __construct(
        private DefinitionRepository $definitions,
        private SettingCache $cache,
    ) {}

    /** Return the effective typed value of a defined setting. */
    public function get(string $key): mixed
    {
        $definition = $this->definitions->get($key);
        $record = $this->cache->record($key);

        return $record instanceof Setting ? $record->resolved() : $definition->default;
    }

    /** Check whether a source-controlled definition exists. */
    public function has(string $key): bool
    {
        try {
            $this->definitions->get($key);

            return true;
        } catch (UnknownSettingException) {
            return false;
        }
    }
}
