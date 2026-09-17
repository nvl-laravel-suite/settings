<?php

declare(strict_types=1);

use Nvl\Settings\Tenancy\SettingsAdoptionAdapter;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

it('registers the package-owned settings adopter', function (): void {
    expect(app(TenantAdoptionRegistry::class)->all()['settings'] ?? null)->toBe(SettingsAdoptionAdapter::class);
});
