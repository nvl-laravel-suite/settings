<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Nvl\Settings\Support\Definition;

/**
 * Enforces definition rules and canonical type encoding for setting values.
 */
final readonly class SettingValueValidator
{
    /**
     * Validate one runtime setting value against its complete definition.
     *
     * @throws ValidationException
     */
    public function validate(Definition $definition, mixed $value, string $attribute = 'value'): void
    {
        $validator = Validator::make(
            ['value' => $value],
            ['value' => [...$definition->type->baseRules(), ...$definition->rules]],
        );

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                $attribute => $validator->errors()->get('value'),
            ]);
        }

        try {
            $definition->type->serialize($value);
        } catch (InvalidArgumentException|JsonException $exception) {
            throw ValidationException::withMessages([
                $attribute => [$exception->getMessage()],
            ]);
        }
    }

    /**
     * Validate and canonicalize one stored override against a new definition.
     *
     *
     * @throws ValidationException
     */
    public function validateStored(Definition $definition, ?string $rawValue): ?string
    {
        try {
            $value = $definition->type->deserialize($rawValue);
        } catch (InvalidArgumentException|JsonException $exception) {
            throw ValidationException::withMessages([
                'value' => [$exception->getMessage()],
            ]);
        }

        $this->validate($definition, $value);

        return $definition->type->serialize($value);
    }
}
