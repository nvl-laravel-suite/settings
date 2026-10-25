<?php

declare(strict_types=1);

namespace Nvl\Settings\Support;

use InvalidArgumentException;

/**
 * Stable value-free identity for a changed setting.
 *
 * @api
 */
final class SettingSubjectReference
{
    /** Stable package-owned activity subject type. */
    public readonly string $type;

    /**
     * Create one storage-compatible setting subject reference.
     */
    public function __construct(public readonly string $id)
    {
        if (preg_match('/\S/', $this->id) !== 1
            || strlen($this->id) > 100
            || str_contains($this->id, "\0")) {
            throw new InvalidArgumentException(
                'Setting subject identifiers must contain between 1 and 100 non-blank bytes without NUL bytes.',
            );
        }

        $this->type = 'nvl_setting';
    }

    /** @return array{type: 'nvl_setting', id: string} */
    public function toArray(): array
    {
        return ['type' => 'nvl_setting', 'id' => $this->id];
    }
}
