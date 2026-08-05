<?php

declare(strict_types=1);

namespace Nvl\Settings\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * Raised when a caller targets a setting absent from the source definitions.
 */
final class UnknownSettingException extends SettingException implements ShouldntReport {}
