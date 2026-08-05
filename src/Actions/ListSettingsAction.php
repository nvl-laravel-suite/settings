<?php

declare(strict_types=1);

namespace Nvl\Settings\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Nvl\Data\Data\PaginatedCollection;
use Nvl\Data\Data\PaginationMeta;
use Nvl\Settings\Data\SettingDefinitionData;
use Nvl\Settings\Data\SettingListQueryData;
use Nvl\Settings\Data\SettingManagementData;
use Nvl\Settings\Data\SettingValueData;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\Definition;
use Nvl\Settings\Support\DefinitionRepository;

/**
 * Returns a deterministic, bounded management page of setting definitions and values.
 */
final readonly class ListSettingsAction
{
    /**
     * Create the settings management-list action.
     */
    public function __construct(
        private DefinitionRepository $definitions,
    ) {}

    /**
     * Resolve one filtered management page.
     */
    public function execute(SettingListQueryData $query): PaginatedCollection
    {
        $definitions = array_filter(
            $this->definitions->all(),
            static function (Definition $definition) use ($query): bool {
                if ($query->namespace !== null
                    && $definition->namespace !== $query->namespace) {
                    return false;
                }

                if ($query->scope !== null && $definition->scope !== $query->scope) {
                    return false;
                }

                if ($query->search === null) {
                    return true;
                }

                $search = Str::lower($query->search);
                $haystack = Str::lower(implode(' ', [
                    $definition->namespace,
                    $definition->scope,
                    $definition->key,
                    $definition->description,
                ]));

                return Str::contains($haystack, $search);
            },
        );
        uasort(
            $definitions,
            static fn (Definition $left, Definition $right): int => [
                $left->position,
                $left->namespace,
                $left->scope,
                $left->key,
            ] <=> [
                $right->position,
                $right->namespace,
                $right->scope,
                $right->key,
            ],
        );
        $total = count($definitions);
        $offset = ($query->page - 1) * $query->perPage;
        $pageDefinitions = array_slice($definitions, $offset, $query->perPage, true);
        $records = collect();

        if ($pageDefinitions !== []) {
            $records = Setting::query()
                ->where(function (Builder $query) use ($pageDefinitions): void {
                    foreach ($pageDefinitions as $definition) {
                        $query->orWhere(function (Builder $identity) use ($definition): void {
                            $identity
                                ->where('namespace', $definition->namespace)
                                ->where('scope', $definition->scope)
                                ->where('key', $definition->key);
                        });
                    }
                })
                ->get()
                ->keyBy(static fn (Setting $setting): string => $setting->fullKey());
        }
        $items = [];

        foreach ($pageDefinitions as $key => $definition) {
            $record = $records->get($key);
            $items[] = (new SettingManagementData(
                definition: SettingDefinitionData::fromDefinition($definition),
                value: $record instanceof Setting
                    ? SettingValueData::fromModel($record)
                    : SettingValueData::fromDefinition($definition),
            ));
        }

        $normalizedItems = [];

        foreach ($items as $item) {
            $normalized = [];

            foreach ($item->toArray() as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }

            $normalizedItems[] = $normalized;
        }

        return new PaginatedCollection(
            items: $normalizedItems,
            meta: new PaginationMeta(
                currentPage: $query->page,
                lastPage: max(1, (int) ceil($total / $query->perPage)),
                perPage: $query->perPage,
                total: $total,
            ),
        );
    }
}
