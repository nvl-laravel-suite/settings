<?php

declare(strict_types=1);

namespace Nvl\Settings\Exceptions;

/**
 * Raised when discovery produces more than one definition for an identity.
 */
final class DuplicateSettingException extends SettingException {}
