<?php

declare(strict_types=1);

namespace Nvl\Settings\Actions;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Nvl\Settings\Data\SettingValueData;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\DefinitionRepository;

/**
 * Resolves a bounded collection of setting keys with one storage query.
 */
final readonly class GetManySettingsAction
{
    /**
     * Create the bulk settings read action.
     */
    public function __construct(private DefinitionRepository $definitions) {}

    /**
     * @param  list<string>  $keys
     * @return list<SettingValueData>
     */
    public function execute(array $keys): array
    {
        $keys = array_values(array_unique($keys));

        if (count($keys) > 500) {
            throw new InvalidArgumentException('At most 500 settings may be resolved at once.');
        }

        $definitions = [];

        foreach ($keys as $key) {
            $definitions[$key] = $this->definitions->get($key);
        }

        if ($definitions === []) {
            return [];
        }

        $records = Setting::query()
            ->where(function (Builder $query) use ($definitions): void {
                foreach ($definitions as $definition) {
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

        return array_map(
            static function (string $key) use ($definitions, $records): SettingValueData {
                $record = $records->get($key);
                $definition = $definitions[$key];

                return $record instanceof Setting
                    ? SettingValueData::fromModel($record)
                    : SettingValueData::fromDefinition($definition);
            },
            $keys,
        );
    }
}
