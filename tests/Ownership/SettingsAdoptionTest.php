<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\PlatformSettingsReader;
use Nvl\Settings\Tests\Fixtures\OwnershipActor;
use Nvl\Settings\Tests\Fixtures\OwnershipCompany;
use Nvl\Settings\Tests\SettingsOwnershipAdoptionTestCase;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(SettingsOwnershipAdoptionTestCase::class);

it('adopts reviewed central and owned rows without inferring either disposition', function (): void {
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, fn () => expect(Setting::query()->pluck('key')->all())->toBe(['private']));
    $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, fn () => expect(Setting::query()->count())->toBe(0));
    $runner->central('settings-config', $actor, fn () => expect(Setting::query()->pluck('key')->all())->toBe(['brand']));
    expect(DB::table((new Setting)->getTable())->where('owner_scope_key', 'central')->value('owner_reference'))->toBeNull();
    config(['nvl-ownership.enabled' => false]);
    expect(fn () => app(PlatformSettingsReader::class)->records())->toThrow(OwnershipViolation::class);
});
