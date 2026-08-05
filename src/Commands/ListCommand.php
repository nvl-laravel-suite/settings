<?php

declare(strict_types=1);

namespace Nvl\Settings\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\DefinitionRepository;
use Stringable;

/**
 * Lists source definitions alongside persisted override state.
 */
final class ListCommand extends Command
{
    protected $signature = 'nvl:settings:list {--namespace=} {--changed}';

    protected $description = 'List registered settings';

    /**
     * Render the filtered setting definitions.
     */
    public function handle(DefinitionRepository $repository): int
    {
        $namespaceOption = $this->option('namespace');
        $namespaceFilter = is_string($namespaceOption) ? $namespaceOption : null;
        $changedOnly = $this->option('changed') === true;

        $definitions = collect($repository->all());
        if ($namespaceFilter) {
            $definitions = $definitions->filter(
                static fn ($definition): bool => $definition->namespace === $namespaceFilter,
            );
        }

        $records = Setting::query()
            ->get()
            ->keyBy(static fn (Setting $setting): string => $setting->fullKey());

        $rows = [];
        foreach ($definitions as $fullKey => $def) {
            $record = $records->get($fullKey);

            $isChanged = $record instanceof Setting && $record->isCustomised();
            if ($changedOnly && ! $isChanged) {
                continue;
            }

            $value = $isChanged ? $record->value : null;
            $fallback = $record ? $record->fallback : $def->default;

            $rows[] = [
                $fullKey,
                $def->type->value,
                $this->formatValue($value),
                $this->formatValue($fallback),
                $isChanged ? 'Yes' : 'No',
                $def->source,
            ];
        }

        $this->table(['Key', 'Type', 'Value', 'Fallback', 'Changed', 'Source'], $rows);

        return self::SUCCESS;
    }

    /**
     * Format one value for bounded console output.
     */
    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return Str::limit((string) $value, 50);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
