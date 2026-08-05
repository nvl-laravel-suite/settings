<?php

declare(strict_types=1);

namespace Nvl\Settings\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Synchronization outcome shared by previews and committed runs.
 *
 * @internal
 */
#[Hidden]
final class SettingSyncResultData extends Data
{
    /**
     * Create a synchronization result.
     *
     * @param  list<string>  $failures
     */
    public function __construct(
        public readonly int $synchronized,
        public readonly int $orphans,
        public readonly array $failures = [],
    ) {}

    /**
     * Determine whether every stored override is compatible.
     */
    public function isValid(): bool
    {
        return $this->failures === [];
    }
}
