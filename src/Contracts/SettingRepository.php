<?php

declare(strict_types=1);

namespace Nvl\Settings\Contracts;

/** Read-only effective values for runtime configuration consumers. */
interface SettingRepository
{
    public function get(string $key): mixed;

    public function has(string $key): bool;
}
