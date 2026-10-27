<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Nvl\Settings\Services\SettingWriter;
use Nvl\Settings\Tests\ConfiguredModelsTestCase;
use Nvl\Settings\Tests\SettingsCacheTestCase;
use Nvl\Settings\Tests\TestCase;

function settingsTestActor(): GenericUser
{
    config()->set('nvl-settings.management.authorization_ability', 'manage-settings');
    Gate::define('manage-settings', static fn (GenericUser $principal): bool => $principal->getAuthIdentifier() === 'user-1');

    return new GenericUser(['id' => 'user-1']);
}

/** @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function settingsSetForTest(string $key, mixed $value, ?int $expectedRevision = 0, array $options = []): array
{
    return app(SettingWriter::class)->set($key, $value, settingsTestActor(), $expectedRevision, $options);
}

uses(TestCase::class)->in(
    'Feature/TenantBootstrapSafetyTest.php',
    'SettingManagerTest.php',
    'SettingsAdoptionTest.php',
    'SettingsConsumerContractsTest.php',
    'SettingsManagementApiTest.php',
    'NativeSettingsServiceTest.php',
);

uses(SettingsCacheTestCase::class)->in('SettingsCacheTest.php');

uses(ConfiguredModelsTestCase::class)->in('ConfiguredModels');
