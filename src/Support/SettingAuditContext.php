<?php

declare(strict_types=1);

namespace Nvl\Settings\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Support\Enums\SystemActor;

/**
 * Value-free actor and request metadata attached to a setting mutation.
 *
 * @api
 */
final readonly class SettingAuditContext
{
    /** @param array{request_id?: ?string, ip_address?: ?string, user_agent?: ?string} $metadata */
    public static function fromActor(Authenticatable|SystemActor $actor, array $metadata = []): self
    {
        if (array_diff(array_keys($metadata), ['request_id', 'ip_address', 'user_agent']) !== []) {
            throw new InvalidArgumentException('Unknown Settings audit metadata key.');
        }
        $type = $actor instanceof SystemActor
            ? SystemActor::class
            : ($actor instanceof Model ? $actor->getMorphClass() : $actor::class);
        $identifier = $actor instanceof SystemActor ? $actor->name : $actor->getAuthIdentifier();
        if ((! is_string($identifier) && ! is_int($identifier)) || $identifier === '') {
            throw new InvalidArgumentException('Settings audit actor requires a scalar identity.');
        }

        return new self(
            actorType: self::bounded($type, 255),
            actorId: self::bounded((string) $identifier, 255),
            requestId: self::metadataValue($metadata['request_id'] ?? null, 128),
            ipAddress: self::metadataValue($metadata['ip_address'] ?? null, 64),
            userAgent: self::metadataValue($metadata['user_agent'] ?? null, 1_024),
        );
    }

    private static function metadataValue(mixed $value, int $maximumBytes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('Settings audit metadata must be strings or null.');
        }

        return self::bounded($value, $maximumBytes);
    }

    private static function bounded(string $value, int $maximumBytes): string
    {
        if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Settings audit context must contain valid text.');
        }

        return mb_strcut($value, 0, $maximumBytes, 'UTF-8');
    }

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
