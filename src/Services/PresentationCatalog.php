<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Nvl\Settings\Exceptions\InvalidDefinitionException;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Support\ScopeDefinition;
use Nvl\Settings\Support\SettingRecord;

/**
 * Provides ordered scope and field metadata for host settings management.
 *
 * @api
 *
 * @phpstan-import-type DefinitionRecord from SettingRecord
 */
final readonly class PresentationCatalog
{
    /** @var array<string, ScopeDefinition> */
    private array $presentation;

    /** @param list<ScopeDefinition> $scopes */
    public function __construct(private DefinitionRepository $definitions, array $scopes = [])
    {
        $presentation = [];
        foreach ($scopes as $scope) {
            if (isset($presentation[$scope->key()])) {
                throw new InvalidDefinitionException("Presentation scope [{$scope->key()}] is duplicated.");
            }
            $presentation[$scope->key()] = $scope;
        }
        $this->presentation = $presentation;
    }

    /** @return list<ScopeDefinition> */
    public function scopes(): array
    {
        $scopes = [];
        foreach ($this->definitions->all() as $definition) {
            $scope = new ScopeDefinition($definition->namespace, $definition->scope);
            $scopes[$scope->key()] = $this->presentation[$scope->key()] ?? $scope;
        }
        usort($scopes, static fn (ScopeDefinition $a, ScopeDefinition $b): int => [$a->order, $a->key()] <=> [$b->order, $b->key()]);

        return $scopes;
    }

    /**
     * List current definitions for a namespace or namespace.scope, ordered by position.
     *
     * @return list<array{key: string, group: ?string, order: int, label: ?string, hint: ?string, definition: DefinitionRecord}>
     */
    public function fields(string $scope): array
    {
        $fields = [];
        foreach ($this->definitions->all() as $key => $definition) {
            $definitionsScope = $definition->scope === '' ? $definition->namespace : $definition->namespace.'.'.$definition->scope;
            if ($scope !== $definitionsScope) {
                continue;
            }
            $fields[] = [
                'key' => $key, 'order' => $definition->position,
                'group' => $this->text($definition->metadata['group'] ?? null),
                'label' => $this->text($definition->metadata['label'] ?? null),
                'hint' => $this->text($definition->metadata['hint'] ?? null),
                'definition' => SettingRecord::definition($definition),
            ];
        }
        usort($fields, static fn (array $a, array $b): int => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);

        return $fields;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
