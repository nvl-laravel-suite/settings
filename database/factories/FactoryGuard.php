<?php

declare(strict_types=1);

namespace Nvl\Settings\Database\Factories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/** Applies admitted ownership facts to Settings fixtures. @internal */
final class FactoryGuard
{
    public static function root(Model $model, string $resource): void
    {
        $model->forceFill(Container::getInstance()->make(OwnershipBoundary::class)->attributes($resource));
    }

    private function __construct() {}
}
