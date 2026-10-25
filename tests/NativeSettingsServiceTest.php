<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Nvl\Settings\Enums\SettingAbility;
use Nvl\Settings\Enums\SettingPruneStrategy;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Events\SettingChanged;
use Nvl\Settings\Exceptions\StaleSettingVersionException;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\SettingReader;
use Nvl\Settings\Services\SettingWriter;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Tests\Fixtures\InteractsWithSettings;
use Nvl\Support\Enums\SystemActor;

uses(InteractsWithSettings::class);

it('requires explicit actor policy and revision for an existing override', function (): void {
    $this->defineSettings(['catalog.value' => ['type' => SettingType::Integer, 'default' => 3]]);
    $actor = new GenericUser(['id' => 'user-1']);
    $writer = app(SettingWriter::class);

    expect(fn () => $writer->set('catalog.value', 4, $actor, 0))
        ->toThrow(AuthorizationException::class);

    config()->set('nvl-settings.management.authorization_ability', 'manage-settings');
    Gate::define('manage-settings', static fn (GenericUser $principal, string $ability, ?string $key): bool => $principal->getAuthIdentifier() === 'user-1'
        && $ability === SettingAbility::Set->value
        && $key === 'catalog.value');

    $created = $writer->set('catalog.value', 4, $actor, 0);
    expect($created['value'])->toBe(4)
        ->and($created['source'])->toBe('database')
        ->and($created['revision'])->toBe(1);
    expect(fn () => $writer->set('catalog.value', 5, $actor))
        ->toThrow(StaleSettingVersionException::class);
    expect(fn () => $writer->set('catalog.value', 5, SystemActor::Background, 1))
        ->toThrow(AuthorizationException::class);
    expect(app(SettingReader::class)->get('catalog.value')['value'])->toBe(4);
});

it('preserves nullable overrides and patches validity windows by presence', function (): void {
    $this->defineSettings(['catalog.optional' => ['type' => SettingType::Text, 'default' => 'fallback', 'rules' => ['nullable']]]);
    $actor = settingsTestActor();
    $writer = app(SettingWriter::class);
    $from = now()->addHour()->startOfSecond()->toAtomString();
    $until = now()->addHours(2)->startOfSecond()->toAtomString();

    $first = $writer->set('catalog.optional', null, $actor, 0, ['valid_from' => $from]);
    expect($first['value'])->toBe('fallback')
        ->and($first['has_override'])->toBeTrue()
        ->and($first['valid_from'])->toBe($from);

    $second = $writer->set('catalog.optional', null, $actor, $first['revision'], ['valid_until' => $until]);
    expect($second['valid_from'])->toBe($from)
        ->and($second['valid_until'])->toBe($until);

    $third = $writer->set('catalog.optional', null, $actor, $second['revision'], ['valid_from' => null]);
    expect($third['valid_from'])->toBeNull()
        ->and($third['value'])->toBeNull()
        ->and($third['source'])->toBe('database');

    $reset = $writer->reset('catalog.optional', $actor, $third['revision']);
    expect($reset['value'])->toBe('fallback')
        ->and($reset['has_override'])->toBeFalse()
        ->and($reset['source'])->toBe('definition');
});

it('validates option fields and rejects inverted and config override windows', function (): void {
    $this->defineSettings([
        'catalog.label' => ['type' => SettingType::Text, 'default' => 'Label'],
        'branding.name' => ['type' => SettingType::Text, 'default' => 'Brand', 'overrides' => 'app.name'],
    ]);
    $actor = settingsTestActor();
    $writer = app(SettingWriter::class);
    expect(fn () => $writer->set('catalog.label', 'new', $actor, 0, ['owner_id' => 'other']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $writer->set('catalog.label', 'new', $actor, 0, [
            'valid_from' => now()->addHours(2)->startOfSecond()->toAtomString(),
            'valid_until' => now()->addHour()->startOfSecond()->toAtomString(),
        ]))->toThrow(ValidationException::class)
        ->and(fn () => $writer->set('branding.name', 'new', $actor, 0, [
            'valid_from' => now()->addHour()->startOfSecond()->toAtomString(),
        ]))->toThrow(ValidationException::class);
    expect(Setting::query()->count())->toBe(0);
});

it('keeps rolled back changes out of events and the shared cache', function (): void {
    $this->defineSettings(['catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false]]);
    $actor = settingsTestActor();
    $reader = app(SettingReader::class);
    Event::fake([SettingChanged::class]);
    $connection = DB::connection((new Setting)->getConnectionName());
    $connection->beginTransaction();
    app(SettingWriter::class)->set('catalog.enabled', true, $actor, 0);
    Event::assertNotDispatched(SettingChanged::class);
    $connection->rollBack();

    expect($reader->get('catalog.enabled')['value'])->toBeFalse()
        ->and(Setting::query()->count())->toBe(0);
    Event::assertNotDispatched(SettingChanged::class);
});

it('preserves a live override when synchronizing a changed definition default', function (): void {
    $definitions = app(DefinitionRepository::class);
    $definitions->fake(['catalog.label' => ['type' => SettingType::Text, 'default' => 'Original']]);
    $actor = settingsTestActor();
    $writer = app(SettingWriter::class);
    $writer->set('catalog.label', 'Custom', $actor, 0);

    $definitions->fake(['catalog.label' => ['type' => SettingType::Text, 'default' => 'Changed']]);
    $writer->sync(prune: SettingPruneStrategy::Orphan);

    expect(app(SettingReader::class)->get('catalog.label')['value'])->toBe('Custom')
        ->and(Setting::query()->firstOrFail()->has_override)->toBeTrue();
});
