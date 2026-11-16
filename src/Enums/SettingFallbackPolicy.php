<?php

declare(strict_types=1);

namespace Nvl\Settings\Enums;

/** Chooses the final fallback after active overrides and available host configuration. @api */
enum SettingFallbackPolicy: string
{
    case CallerDefault = 'caller_default';
    case DefinitionDefault = 'definition_default';
}
