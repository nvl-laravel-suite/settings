<?php

declare(strict_types=1);

namespace Nvl\Settings\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One non-mutating Settings health result.
 */
#[TypeScript]
final class SettingsDoctorCheckData extends Data
{
    /**
     * Create one readiness diagnostic.
     */
    public function __construct(
        public readonly string $key,
        #[LiteralTypeScriptType("'error' | 'warning'")]
        public readonly string $severity,
        public readonly bool $passed,
        public readonly string $message,
    ) {}
}
