<?php

declare(strict_types=1);

namespace Nvl\Settings\Adapters\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Nvl\Settings\Contracts\SettingsAuditContextProvider;
use Nvl\Settings\Data\SettingAuditContextData;

/**
 * Captures bounded audit metadata from Laravel's current request.
 */
final readonly class LaravelSettingsAuditContextProvider implements SettingsAuditContextProvider
{
    /**
     * Create the Laravel request adapter.
     */
    public function __construct(private Request $request) {}

    /** {@inheritDoc} */
    public function current(): SettingAuditContextData
    {
        $actor = $this->request->user();

        return new SettingAuditContextData(
            actorType: $this->actorType($actor),
            actorId: $this->actorIdentifier($actor),
            requestId: $this->bounded($this->request->headers->get('X-Request-ID'), 128),
            ipAddress: $this->bounded($this->request->ip(), 64),
            userAgent: $this->bounded($this->request->userAgent(), 1_024),
        );
    }

    /**
     * Resolve the stable model alias or concrete contract type for an audit actor.
     */
    private function actorType(?Authenticatable $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        return $this->bounded(
            $actor instanceof Model ? $actor->getMorphClass() : $actor::class,
            255,
        );
    }

    /**
     * Resolve a scalar authentication identifier without coercing unsupported values.
     */
    private function actorIdentifier(?Authenticatable $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        $identifier = $actor->getAuthIdentifier();

        if (! is_string($identifier) && ! is_int($identifier)) {
            return null;
        }

        return $this->bounded((string) $identifier, 255);
    }

    /**
     * Bound untrusted request metadata without allowing audit capture to fail a mutation.
     */
    private function bounded(?string $value, int $maximumBytes): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return substr($value, 0, $maximumBytes);
    }
}
