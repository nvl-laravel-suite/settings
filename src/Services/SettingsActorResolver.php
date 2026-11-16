<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Support\Auth\CanonicalActorResolver;
use Nvl\Support\Enums\SystemActor;

/**
 * Resolves the principal a Settings write or adoption phase authorizes.
 *
 * @internal
 */
final readonly class SettingsActorResolver
{
    public function __construct(private CanonicalActorResolver $actors) {}

    /**
     * Require a non-empty string or integer identifier, then reload Eloquent actors from the write connection.
     */
    public function resolve(Authenticatable|SystemActor $actor): Authenticatable|SystemActor
    {
        if ($actor instanceof SystemActor) {
            return $actor;
        }
        $identifier = $actor->getAuthIdentifier();
        if ((! is_string($identifier) && ! is_int($identifier)) || $identifier === '') {
            throw new AuthorizationException('Settings mutations require a persisted actor.');
        }

        return $this->actors->resolve($actor);
    }
}
