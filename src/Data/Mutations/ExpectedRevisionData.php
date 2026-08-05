<?php

declare(strict_types=1);

namespace Nvl\Settings\Data\Mutations;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

#[MapInputName(CamelCaseMapper::class)]
final class ExpectedRevisionData extends Data
{
    public function __construct(
        public readonly int $expectedRevision,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ];
    }
}
