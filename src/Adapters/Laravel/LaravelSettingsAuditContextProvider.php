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
            actorType: $actor instanceof Model
                ? $this->bounded($actor->getMorphClass(), 255)
                : ($actor instanceof Authenticatable ? $this->bounded($actor::class, 255) : null),
            actorId: $actor instanceof Authenticatable
                ? $this->bounded((string) $actor->getAuthIdentifier(), 255)
                : null,
            requestId: $this->bounded($this->request->headers->get('X-Request-ID'), 128),
            ipAddress: $this->bounded($this->request->ip(), 64),
            userAgent: $this->bounded($this->request->userAgent(), 1_024),
        );
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
