<?php

declare(strict_types=1);

use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Settings\Ownership\SettingsAdoptionAdapter;
use Nvl\Settings\Tests\OwnershipTestCase;

uses(OwnershipTestCase::class);

it('registers the package-owned Settings ownership adopter', function (): void {
    expect(app(OwnershipAdoptionRegistry::class)->all()['settings'] ?? null)->toBe(SettingsAdoptionAdapter::class);
});
