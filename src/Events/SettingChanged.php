<?php

declare(strict_types=1);

namespace Nvl\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nvl\Settings\Data\SettingAuditContextData;
use Nvl\Settings\Data\SettingSubjectReferenceData;
use Nvl\Tenancy\Contracts\TenantQueuedJob;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

/**
 * Signals a committed runtime setting mutation without serializing its value.
 */
final readonly class SettingChanged implements ShouldDispatchAfterCommit, TenantQueuedJob
{
    use Dispatchable;

    public SettingAuditContextData $context;

    public SettingSubjectReferenceData $subject;

    /**
     * Create value-free mutation metadata for committed listeners.
     */
    public function __construct(
        public string $id,
        public string $key,
        public int $revision,
        public string $operation,
        ?SettingAuditContextData $context = null,
        public ?string $tenantId = null,
        public string $ownershipKey = 'platform',
        private ?TenantJobEnvelope $envelope = null,
    ) {
        $this->context = $context ?? new SettingAuditContextData;
        $this->subject = new SettingSubjectReferenceData($this->id);
    }

    /** Return producer ownership captured before after-commit dispatch. */
    public function tenantJobEnvelope(): TenantJobEnvelope
    {
        if (! $this->envelope instanceof TenantJobEnvelope) {
            throw new \LogicException('Setting changes require a captured tenant job envelope.');
        }

        return $this->envelope;
    }
}
