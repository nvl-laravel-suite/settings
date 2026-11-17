<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Accepts only a JSON map whose values are floats, as a consumer ratio rule would. */
final readonly class FloatMapRule implements ValidationRule
{
    /** {@inheritDoc} */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (is_array($value) ? $value : [null] as $item) {
            if (! is_float($item)) {
                $fail('The :attribute must contain only floats.');

                return;
            }
        }
    }
}
