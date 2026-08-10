<?php

declare(strict_types=1);

namespace Nvl\Settings\Data;

/**
 * Value-free actor and request metadata attached to a setting mutation.
 */
final readonly class SettingAuditContextData
{
    /**
     * Create one immutable audit-context snapshot.
     */
    public function __construct(
        public ?string $actorType = null,
        public ?string $actorId = null,
        public ?string $requestId = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {}
}
