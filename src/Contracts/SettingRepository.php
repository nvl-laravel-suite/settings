<?php

declare(strict_types=1);

namespace Nvl\Settings\Contracts;

/**
 * Exposes the minimal consumer-facing settings read and mutation contract.
 */
interface SettingRepository
{
    /**
     * Return the effective value for one registered setting.
     */
    public function get(string $key): mixed;

    /**
     * Persist one runtime setting override.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Persist multiple runtime setting overrides atomically.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void;

    /**
     * Clear one runtime override.
     */
    public function forget(string $key): void;

    /**
     * Determine whether one setting definition exists.
     */
    public function has(string $key): bool;
}
