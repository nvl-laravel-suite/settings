<?php

declare(strict_types=1);

namespace Nvl\Settings\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Validated filtering and pagination input for management setting definitions.
 */
#[TypeScript]
final class SettingListQueryData extends Data
{
    /**
     * Create a bounded management-list query.
     */
    public function __construct(
        public readonly ?string $namespace = null,
        public readonly ?string $scope = null,
        public readonly ?string $search = null,
        public readonly int $page = 1,
        public readonly int $perPage = 50,
    ) {}

    /**
     * Return validation rules for the management-list query.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'namespace' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'scope' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['integer', 'min:1'],
            'perPage' => ['integer', 'min:1', 'max:100'],
        ];
    }
}
