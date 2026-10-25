<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests\Fixtures;

use Illuminate\Http\Request;
use Nvl\Settings\Contracts\SettingsAuditContextProvider;

/** Captures optional bounded request facts without consulting the HTTP principal. */
final readonly class LaravelSettingsAuditContextProvider implements SettingsAuditContextProvider
{
    public function __construct(private Request $request) {}

    public function metadata(): array
    {
        return [
            'request_id' => $this->bounded($this->request->headers->get('X-Request-ID'), 128),
            'ip_address' => $this->bounded($this->request->ip(), 64),
            'user_agent' => $this->bounded($this->request->userAgent(), 1_024),
        ];
    }

    private function bounded(?string $value, int $maximumBytes): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return substr($value, 0, $maximumBytes);
    }
}
