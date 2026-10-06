<?php

declare(strict_types=1);

namespace Nvl\Settings\Events;

use Nvl\Settings\Data\SettingAuditContextData;
use Nvl\Settings\Data\SettingSubjectReferenceData;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Tenancy\Contracts\TenantQueuedJob;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;

/**
 * Signals a committed runtime setting mutation without serializing its value.
 *
 * @api
 */
final readonly class SettingChanged implements DomainEvent, TenantQueuedJob
{
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
        public int $schemaVersion = 1,
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

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
