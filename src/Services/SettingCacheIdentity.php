<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

/** Identifies one immutable tenant-partitioned Settings cache projection. */
final readonly class SettingCacheIdentity
{
    /** Create a cache identity from an optional store and fully-qualified key. */
    public function __construct(public ?string $store, public string $key) {}
}
