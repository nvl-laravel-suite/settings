<?php

declare(strict_types=1);

namespace Nvl\Settings\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Stringable;

/**
 * Validates a JSON object with safe string keys and bounded integer values.
 */
final readonly class IntegerMapBetween implements Stringable, ValidationRule
{
    /**
     * Create the bounded integer-map rule.
     */
    public function __construct(
        public int $minimum,
        public int $maximum,
    ) {
        if ($minimum > $maximum) {
            throw new InvalidArgumentException('The integer-map minimum cannot exceed its maximum.');
        }
    }

    /**
     * Determine whether the complete JSON value satisfies this rule.
     */
    public function isValid(mixed $value): bool
    {
        if (! is_array($value) || array_is_list($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (! is_string($key)
                || preg_match('/^[A-Za-z0-9_-]+$/D', $key) !== 1
                || ! is_int($item)
                || $item < $this->minimum
                || $item > $this->maximum) {
                return false;
            }
        }

        return true;
    }

    /** {@inheritDoc} */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->isValid($value)) {
            $fail("The :attribute must be a string-keyed map of integers between {$this->minimum} and {$this->maximum}.");
        }
    }

    /**
     * Return the portable JSON-source rule representation.
     */
    public function __toString(): string
    {
        return "settings_integer_map_between:{$this->minimum},{$this->maximum}";
    }
}
