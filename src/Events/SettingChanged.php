<?php

declare(strict_types=1);

namespace Nvl\Settings\Events;

use Nvl\Settings\Support\SettingAuditContext;
use Nvl\Settings\Support\SettingSubjectReference;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Signals a committed runtime setting mutation without serializing its value.
 *
 * @api
 */
final readonly class SettingChanged implements DomainEvent, OwnershipQueuedJob
{
    public SettingAuditContext $context;

    public SettingSubjectReference $subject;

    /**
     * Create value-free mutation metadata for committed listeners.
     */
    public function __construct(
        public string $id,
        public string $key,
        public int $revision,
        public string $operation,
        ?SettingAuditContext $context = null,
        private ?OwnershipJobEnvelope $ownershipEnvelope = null,
        public int $schemaVersion = 1,
    ) {
        $this->context = $context ?? new SettingAuditContext;
        $this->subject = new SettingSubjectReference($this->id);
    }

    /** Return ownership captured before commit without retaining any current principal. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope ?? throw new \LogicException('Setting changes require captured ownership.');
    }

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
