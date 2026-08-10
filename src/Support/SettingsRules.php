<?php

declare(strict_types=1);

namespace Nvl\Settings\Support;

use InvalidArgumentException;
use Nvl\Settings\Rules\IntegerListBetween;
use Nvl\Settings\Rules\IntegerMapBetween;

/**
 * Builds deterministic validation rules for structured JSON settings.
 */
final class SettingsRules
{
    /**
     * Build a bounded integer-list rule for a PHP definition.
     */
    public static function integerListBetween(int $minimum, int $maximum): IntegerListBetween
    {
        return new IntegerListBetween($minimum, $maximum);
    }

    /**
     * Build a bounded integer-map rule for a PHP definition.
     */
    public static function integerMapBetween(int $minimum, int $maximum): IntegerMapBetween
    {
        return new IntegerMapBetween($minimum, $maximum);
    }

    /**
     * Build the list rule from one portable string rule's parameters.
     *
     * @param  list<string>  $parameters
     */
    public static function integerListBetweenParameters(array $parameters): IntegerListBetween
    {
        [$minimum, $maximum] = self::integerBounds($parameters);

        return self::integerListBetween($minimum, $maximum);
    }

    /**
     * Build the map rule from one portable string rule's parameters.
     *
     * @param  list<string>  $parameters
     */
    public static function integerMapBetweenParameters(array $parameters): IntegerMapBetween
    {
        [$minimum, $maximum] = self::integerBounds($parameters);

        return self::integerMapBetween($minimum, $maximum);
    }

    /**
     * Validate exactly two canonical integer bounds.
     *
     * @param  array<int|string, mixed>  $parameters
     * @return array{int, int}
     */
    private static function integerBounds(array $parameters): array
    {
        if (! array_is_list($parameters) || count($parameters) !== 2) {
            throw new InvalidArgumentException('Settings integer collection rules require minimum and maximum parameters.');
        }

        $bounds = [];

        foreach ($parameters as $parameter) {
            if (! is_string($parameter)
                || filter_var($parameter, FILTER_VALIDATE_INT) === false
                || (string) (int) $parameter !== $parameter) {
                throw new InvalidArgumentException('Settings integer collection bounds must be canonical integers.');
            }

            $bounds[] = (int) $parameter;
        }

        return [$bounds[0], $bounds[1]];
    }
}
