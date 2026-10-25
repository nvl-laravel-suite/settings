<?php

declare(strict_types=1);

namespace Nvl\Settings\Support;

/**
 * One non-mutating Settings health result.
 */
final readonly class SettingsDoctorCheck
{
    /**
     * Create one readiness diagnostic.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        public readonly bool $passed,
        public readonly string $message,
    ) {}
}
