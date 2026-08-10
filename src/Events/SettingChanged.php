<?php

declare(strict_types=1);

namespace Nvl\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nvl\Settings\Data\SettingAuditContextData;

/**
 * Signals a committed runtime setting mutation without serializing its value.
 */
final readonly class SettingChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public SettingAuditContextData $context;

    /**
     * Create value-free mutation metadata for committed listeners.
     */
    public function __construct(
        public string $id,
        public string $key,
        public int $revision,
        public string $operation,
        ?SettingAuditContextData $context = null,
    ) {
        $this->context = $context ?? new SettingAuditContextData;
    }
}
