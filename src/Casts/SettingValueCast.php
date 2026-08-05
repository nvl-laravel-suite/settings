<?php

declare(strict_types=1);

namespace Nvl\Settings\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Settings\Enums\SettingType;

/**
 * Serializes setting values through the type stored on the same model row.
 *
 * @implements CastsAttributes<mixed, mixed>
 */
final class SettingValueCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("Setting attribute [{$key}] must contain a string or null.");
        }

        return $this->type($attributes, $key)->deserialize($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => $this->type($attributes, $key)->serialize($value)];
    }

    /**
     * Resolve and validate the sibling type attribute.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function type(array $attributes, string $key): SettingType
    {
        $type = $attributes['type'] ?? null;

        if ($type instanceof SettingType) {
            return $type;
        }

        if (! is_string($type)) {
            throw new InvalidArgumentException("Setting attribute [{$key}] requires a valid type.");
        }

        return SettingType::from($type);
    }
}
