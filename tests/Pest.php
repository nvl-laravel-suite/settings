<?php

declare(strict_types=1);

use Nvl\Settings\Tests\SettingsCacheTestCase;
use Nvl\Settings\Tests\TestCase;

uses(TestCase::class)->in(
    'Feature/TenantBootstrapSafetyTest.php',
    'SettingManagerTest.php',
    'SettingsAdoptionTest.php',
    'SettingsConsumerContractsTest.php',
    'SettingsManagementApiTest.php',
);

uses(SettingsCacheTestCase::class)->in('SettingsCacheTest.php');
