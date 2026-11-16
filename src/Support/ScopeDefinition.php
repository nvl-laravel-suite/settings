<?php

declare(strict_types=1);

namespace Nvl\Settings\Support;

use Nvl\Settings\Exceptions\InvalidDefinitionException;

/**
 * Host presentation metadata for one explicitly identified settings scope.
 *
 * @api
 */
final readonly class ScopeDefinition
{
    public function __construct(
        public string $namespace,
        public string $scope = '',
        public ?string $label = null,
        public ?string $hint = null,
        public int $order = 0,
    ) {
        if (! SettingKey::segment($namespace) || ($scope !== '' && ! SettingKey::segment($scope))) {
            throw new InvalidDefinitionException('Presentation scopes require a valid namespace and optional scope.');
        }
    }

    public function key(): string
    {
        return $this->scope === '' ? $this->namespace : $this->namespace.'.'.$this->scope;
    }
}
