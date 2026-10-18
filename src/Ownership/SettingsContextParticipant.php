<?php

declare(strict_types=1);

namespace Nvl\Settings\Ownership;

use Closure;
use Nvl\Settings\Services\PlatformSettingsRuntime;
use Nvl\Support\Ownership\Contracts\OwnershipContextParticipant;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

/** Restores central config projections across nested and retained ownership workflows. @internal */
final readonly class SettingsContextParticipant implements OwnershipContextParticipant
{
    /** Resolve this lifecycle's projection stack through the container. */
    public function __construct(private PlatformSettingsRuntime $runtime) {}

    /** Capture and restore the exact preceding allowlisted configuration. */
    public function enter(OwnershipSnapshot $next): Closure
    {
        try {
            $this->runtime->apply();
        } catch (\Throwable $exception) {
            $this->runtime->restore();
            throw $exception;
        }

        return fn () => $this->runtime->restore();
    }
}
