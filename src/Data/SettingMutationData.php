<?php

declare(strict_types=1);

namespace Nvl\Settings\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Optimistic setting mutation contract.
 */
#[TypeScript]
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
final class SettingMutationData extends Data
{
    /**
     * Create one optimistic setting mutation contract.
     */
    public function __construct(
        public readonly string $key,
        #[LiteralTypeScriptType('unknown')]
        public readonly mixed $value,
        public readonly ?int $expectedRevision = null,
        #[TypeScriptOptional]
        public readonly string|Optional|null $validFrom = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $validUntil = new Optional,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:302'],
            'value' => ['present'],
            'expectedRevision' => ['required', 'integer', 'min:0'],
            'validFrom' => ['sometimes', 'nullable', 'date'],
            'validUntil' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
