<?php

declare(strict_types=1);

use Nvl\Settings\Support\DefinitionFileLoader;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Tests\TestCase;

uses(TestCase::class);

it('discovers host settings from the default path without scanning the suite source tree', function (): void {
    $defaults = require dirname(__DIR__, 2).'/config/nvl-settings.php';
    expect($defaults['discovery']['paths'])->toBe([base_path('settings')]);
    $directory = base_path('settings');
    if (! is_dir($directory)) {
        mkdir($directory);
    }
    $path = $directory.'/acceptance.settings.php';
    file_put_contents($path, '<?php return ["namespace" => "acceptance", "settings" => ["enabled" => ["type" => "bool", "default" => true]]];');
    try {
        config()->set('nvl-settings.discovery.paths', $defaults['discovery']['paths']);
        config()->set('nvl-settings.discovery.cache', false);
        $definitions = (new DefinitionRepository(new DefinitionFileLoader, app()))->all();
        expect(array_keys($definitions))->toContain('acceptance.enabled');
        foreach ($definitions as $definition) {
            expect($definition->namespace)->toBe('acceptance');
        }
    } finally {
        unlink($path);
    }
});
