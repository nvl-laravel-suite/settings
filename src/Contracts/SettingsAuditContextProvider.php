<?php

declare(strict_types=1);

namespace Nvl\Settings\Contracts;

/** Supplies bounded transport metadata without deciding mutation attribution. @api */
interface SettingsAuditContextProvider
{
    /** @return array{request_id?: ?string, ip_address?: ?string, user_agent?: ?string} */
    public function metadata(): array;
}
