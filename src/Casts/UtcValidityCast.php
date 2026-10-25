<?php

declare(strict_types=1);

namespace Nvl\Settings\Casts;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Stores validity windows as timezone-free UTC seconds and reads them as UTC instants.
 *
 * @implements CastsAttributes<Carbon, DateTimeInterface|string>
 */
final class UtcValidityCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("Stored setting validity [{$key}] must be a UTC timestamp string.");
        }

        $parsed = Carbon::createFromFormat('!Y-m-d H:i:s', $value, 'UTC');
        if (! $parsed instanceof Carbon || $parsed->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException("Stored setting validity [{$key}] must be a UTC timestamp string.");
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, ?string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }
        $parsed = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value, 'UTC');
        if ($parsed->format('u') !== '000000') {
            throw new InvalidArgumentException('Setting validity timestamps require whole-second precision.');
        }

        return [$key => $parsed->setTimezone('UTC')->format('Y-m-d H:i:s')];
    }
}
