<?php

declare(strict_types=1);

namespace Nvl\Settings\Support;

/** @internal */
final class SettingKey
{
    public static function segment(string $value): bool
    {
        return strlen($value) <= DefinitionRepository::MAX_SEGMENT_LENGTH && preg_match('/^[A-Za-z0-9_-]+$/D', $value) === 1;
    }

    public static function leaf(string $value): bool
    {
        if (strlen($value) > DefinitionRepository::MAX_LEAF_LENGTH) {
            return false;
        }
        foreach (explode('.', $value) as $segment) {
            if (! self::segment($segment)) {
                return false;
            }
        }

        return true;
    }

    public static function full(string $namespace, string $scope, string $leaf): string
    {
        return $scope === '' ? "{$namespace}.{$leaf}" : "{$namespace}.{$scope}.{$leaf}";
    }
}
