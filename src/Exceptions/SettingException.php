<?php

declare(strict_types=1);

namespace Nvl\Settings\Exceptions;

use Exception;
use Nvl\Settings\Enums\SettingsResponseCode;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;

/**
 * @api
 * Base exception for failures owned by the Settings package.
 */
abstract class SettingException extends Exception implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Resolve the declared safe failure for this native hierarchy. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return match (static::class) {
            StaleSettingVersionException::class => new ExceptionResponse('settings', SettingsResponseCode::StaleSettingVersion, 409),
            UnknownSettingException::class => new ExceptionResponse('settings', SettingsResponseCode::UnknownSetting, 404),
            DuplicateSettingException::class => new ExceptionResponse('settings', SettingsResponseCode::DuplicateSetting, 409),
            InvalidDefinitionException::class => new ExceptionResponse('settings', SettingsResponseCode::InvalidDefinition, 500),
            default => new ExceptionResponse('settings', SettingsResponseCode::OperationFailed),
        };
    }
}
