<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\Definition;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Support\SettingRecord;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Resolves defined settings and bounded management pages inside the admitted ownership scope.
 *
 * @phpstan-import-type Resolved from SettingRecord
 * @phpstan-import-type DefinitionRecord from SettingRecord
 */
final readonly class SettingReader
{
    public function __construct(
        private DefinitionRepository $definitions,
        private OwnershipBoundary $boundary,
    ) {}

    /** @return array<string, mixed> Effective value, source and revision metadata. */
    public function get(string $key): array
    {
        $definition = $this->definitions->get($key);
        $setting = $this->identityQuery($definition)->first();

        return SettingRecord::resolved($definition, $setting);
    }

    /**
     * @param  array<array-key, mixed>  $keys  Must be a list of at most 500 strings.
     * @return list<array<string, mixed>>
     */
    public function getMany(array $keys): array
    {
        if (! array_is_list($keys) || count($keys) > 500) {
            throw new InvalidArgumentException('At most 500 setting keys must be supplied as a list.');
        }
        $definitions = [];
        foreach ($keys as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Setting keys must be strings.');
            }
            $definitions[$key] = $this->definitions->get($key);
        }
        $keys = array_values(array_unique($keys));
        if ($definitions === []) {
            return [];
        }

        $records = $this->recordsFor($definitions);

        return array_map(
            static fn (string $key): array => SettingRecord::resolved($definitions[$key], $records[$key] ?? null),
            $keys,
        );
    }

    /**
     * Filter keys: namespace, scope, search, page and per_page. Returns definition/value records.
     *
     * @param  array<string, mixed>  $query
     * @return LengthAwarePaginator<int, array{definition: DefinitionRecord, value: Resolved}>
     */
    public function paginate(array $query = []): LengthAwarePaginator
    {
        if (array_diff(array_keys($query), ['namespace', 'scope', 'search', 'page', 'per_page']) !== []) {
            throw new InvalidArgumentException('Unknown settings page filter.');
        }
        $validated = Validator::make($query, [
            'namespace' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'scope' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:100'],
        ])->validate();
        $page = filter_var($validated['page'] ?? 1, FILTER_VALIDATE_INT);
        $perPage = filter_var($validated['per_page'] ?? 50, FILTER_VALIDATE_INT);
        $namespace = $validated['namespace'] ?? null;
        $scope = $validated['scope'] ?? null;
        $search = $validated['search'] ?? null;
        if (! is_int($page) || ! is_int($perPage)
            || ($namespace !== null && ! is_string($namespace))
            || ($scope !== null && ! is_string($scope))
            || ($search !== null && ! is_string($search))) {
            throw new InvalidArgumentException('Settings page filters have invalid types.');
        }
        $definitions = array_filter($this->definitions->all(), static function (Definition $definition) use ($namespace, $scope, $search): bool {
            if ($namespace !== null && $definition->namespace !== $namespace) {
                return false;
            }
            if ($scope !== null && $definition->scope !== $scope) {
                return false;
            }

            return $search === null || Str::contains(Str::lower(implode(' ', [
                $definition->namespace, $definition->scope, $definition->key, $definition->description,
            ])), Str::lower($search));
        });
        uasort($definitions, static fn (Definition $left, Definition $right): int => [
            $left->position, $left->namespace, $left->scope, $left->key,
        ] <=> [
            $right->position, $right->namespace, $right->scope, $right->key,
        ]);
        $total = count($definitions);
        $pageDefinitions = array_slice($definitions, ($page - 1) * $perPage, $perPage, true);
        $records = $this->recordsFor($pageDefinitions);

        return new LengthAwarePaginator($this->pageItems($pageDefinitions, $records), $total, $perPage, $page);
    }

    /**
     * @param  array<string, Definition>  $definitions
     * @param  array<string, Setting>  $records
     * @return list<array{definition: DefinitionRecord, value: Resolved}>
     */
    private function pageItems(array $definitions, array $records): array
    {
        $items = [];
        foreach ($definitions as $key => $definition) {
            $items[] = $this->recordPair($definition, $records[$key] ?? null);
        }

        return $items;
    }

    /** @return array{definition: DefinitionRecord, value: Resolved} */
    private function recordPair(Definition $definition, ?Setting $setting): array
    {
        return [
            'definition' => SettingRecord::definition($definition),
            'value' => SettingRecord::resolved($definition, $setting),
        ];
    }

    /** @param array<string, Definition> $definitions
     * @return array<string, Setting>
     */
    private function recordsFor(array $definitions): array
    {
        if ($definitions === []) {
            return [];
        }

        return $this->boundary->query(Setting::query(), 'settings.values')
            ->where(function (Builder $query) use ($definitions): void {
                foreach ($definitions as $definition) {
                    $query->orWhere(static function (Builder $identity) use ($definition): void {
                        $identity->where('namespace', $definition->namespace)
                            ->where('scope', $definition->scope)
                            ->where('key', $definition->key);
                    });
                }
            })->get()->keyBy(static fn (Setting $setting): string => $setting->fullKey())->all();
    }

    /** @return Builder<Setting> */
    private function identityQuery(Definition $definition): Builder
    {
        return $this->boundary->query(Setting::query(), 'settings.values')->where([
            'namespace' => $definition->namespace,
            'scope' => $definition->scope,
            'key' => $definition->key,
        ]);
    }
}
