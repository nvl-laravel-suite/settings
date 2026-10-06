<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/** Projects opted-in platform overrides for one request or job and restores its original configuration. */
final class PlatformSettingsRuntime
{
    /** @var list<array<string, mixed>|null> */
    private array $originals = [];

    /** Retain this lifecycle's platform reader and stateless configuration writer. */
    public function __construct(
        private readonly Repository $configuration,
        private readonly PlatformSettingsReader $settings,
        private readonly PlatformConfigWriter $writer,
    ) {}

    /**
     * Begin one explicit runtime boundary; restore() must be called when it exits.
     *
     * @throws Throwable When runtime storage or an override mapping is invalid
     */
    public function apply(): void
    {
        $this->originals[] = null;
        $frame = array_key_last($this->originals);
        if ($this->configuration->get('nvl-settings.overrides.enabled') !== true) {
            return;
        }
        try {
            if (! $this->settings->available()) {
                return;
            }
            $records = $this->settings->records();
            $this->originals[$frame] = $this->writer->snapshot();
            $this->writer->apply($records);
        } catch (Throwable $exception) {
            if ($this->originals[$frame] !== null) {
                $this->writer->restore($this->originals[$frame]);
                $this->originals[$frame] = null;
            }
            throw $exception;
        }
    }

    /** Restore only the allowlisted configuration values that this lifecycle changed. */
    public function restore(): void
    {
        $original = array_pop($this->originals);
        if ($original !== null) {
            $this->writer->restore($original);
        }
    }

    /**
     * Run an explicitly chosen operation with platform overrides and restore them on exit.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     *
     * @throws Throwable When projection or the selected operation fails
     */
    public function run(Closure $operation): mixed
    {
        try {
            $this->apply();

            return $operation();
        } finally {
            $this->restore();
        }
    }
}
