<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
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
use Nvl\Settings\Tests\Fixtures\OwnershipActor;
use Nvl\Support\Enums\SystemActor;

uses(InteractsWithSettings::class);

it('authorizes Eloquent actors using canonical persisted attributes', function (string $identity, string $operation): void {
    $this->defineSettings(['catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false]]);
    settingsSetForTest('catalog.enabled', false);
    Schema::create('settings_test_actors', function (Blueprint $table): void {
        $table->id();
        $table->boolean('may_manage');
    });
    $actor = OwnershipActor::query()->create(['may_manage' => $identity !== 'modified']);
    if ($identity === 'deleted') {
        OwnershipActor::query()->whereKey($actor->getKey())->delete();
    } elseif ($identity === 'revoked') {
        OwnershipActor::query()->whereKey($actor->getKey())->update(['may_manage' => false]);
    } elseif ($identity === 'table') {
        $actor->setTable('substituted_actors');
    } else {
        $actor->setAttribute('may_manage', true);
    }
    Gate::define('manage-settings', static fn (OwnershipActor $principal): bool => (bool) $principal->getAttribute('may_manage'));
    Event::fake([SettingChanged::class]);
    $writer = app(SettingWriter::class);
    expect(fn () => $operation === 'set'
        ? $writer->set('catalog.enabled', true, $actor, 1)
        : $writer->reset('catalog.enabled', $actor, 1))->toThrow(AuthorizationException::class);
    expect(Setting::query()->sole()->revision)->toBe(1)
        ->and(Setting::query()->sole()->value)->toBeFalse();
    Event::assertNotDispatched(SettingChanged::class);
})->with(['modified', 'deleted', 'revoked', 'table'])->with(['set', 'reset']);

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

it('stores validity instants in UTC across object and string inputs without advancing equivalent revisions', function (): void {
    $this->defineSettings(['catalog.window' => ['type' => SettingType::Text, 'default' => 'Default']]);
    $actor = settingsTestActor();
    $writer = app(SettingWriter::class);
    $previousTimezone = date_default_timezone_get();
    date_default_timezone_set('America/New_York');
    try {
        $created = $writer->set('catalog.window', 'Custom', $actor, 0, [
            'valid_from' => new DateTimeImmutable('2036-10-25T12:00:00+03:00'),
            'valid_until' => '2036-10-25T15:00:00+03:00',
        ]);
        $record = Setting::query()->firstOrFail();
        expect($record->getRawOriginal('valid_from'))->toBe('2036-10-25 09:00:00')
            ->and($record->getRawOriginal('valid_until'))->toBe('2036-10-25 12:00:00')
            ->and($created['valid_from'])->toBe('2036-10-25T09:00:00+00:00');
        expect(Setting::query()->firstOrFail()->valid_from?->toAtomString())
            ->toBe('2036-10-25T09:00:00+00:00');

        Carbon::setTestNow('2036-10-25T08:59:59Z');
        expect(app(SettingReader::class)->get('catalog.window')['source'])->toBe('definition');
        Carbon::setTestNow('2036-10-25T09:00:01Z');
        expect(app(SettingReader::class)->get('catalog.window')['source'])->toBe('database');

        $same = $writer->set('catalog.window', 'Custom', $actor, $created['revision'], [
            'valid_from' => '2036-10-25T09:00:00Z',
        ]);
        expect($same['revision'])->toBe($created['revision']);

        $updated = $writer->set('catalog.window', 'Custom', $actor, $same['revision'], [
            'valid_from' => '2036-10-25T11:00:00+03:00',
        ]);
        expect(Setting::query()->firstOrFail()->getRawOriginal('valid_from'))->toBe('2036-10-25 08:00:00')
            ->and($updated['valid_from'])->toBe('2036-10-25T08:00:00+00:00')
            ->and(app(SettingReader::class)->get('catalog.window')['source'])->toBe('database');
        Carbon::setTestNow('2036-10-25T12:00:01Z');
        expect(app(SettingReader::class)->get('catalog.window')['source'])->toBe('definition');
    } finally {
        Carbon::setTestNow();
        date_default_timezone_set($previousTimezone);
    }
});

it('reports a persisted codec with its persisted value before sync and after a failed type change', function (): void {
    $definitions = app(DefinitionRepository::class);
    $definitions->fake([
        'catalog.first' => ['type' => SettingType::Text, 'default' => 'First'],
        'catalog.value' => ['type' => SettingType::Text, 'default' => 'Original'],
    ]);
    $writer = app(SettingWriter::class);
    $writer->sync();
    $writer->set('catalog.value', 'not-an-integer', settingsTestActor(), 1);
    $storedHash = Setting::query()->where('key', 'value')->firstOrFail()->definition_hash;
    $firstHash = Setting::query()->where('key', 'first')->firstOrFail()->definition_hash;

    $definitions->fake([
        'catalog.first' => ['type' => SettingType::Text, 'default' => 'Changed'],
        'catalog.value' => ['type' => SettingType::Integer, 'default' => 5],
    ]);
    $reader = app(SettingReader::class);
    $before = $reader->get('catalog.value');
    expect($before['value'])->toBe('not-an-integer')
        ->and($before['type'])->toBe(SettingType::Text)
        ->and($before['definition_hash'])->toBe($storedHash);

    expect(fn () => $writer->sync())->toThrow(ValidationException::class);
    $after = $reader->get('catalog.value');
    expect($after['value'])->toBe('not-an-integer')
        ->and($after['type'])->toBe(SettingType::Text)
        ->and($after['definition_hash'])->toBe($storedHash)
        ->and(Setting::query()->where('key', 'value')->firstOrFail()->type)->toBe(SettingType::Text)
        ->and(Setting::query()->where('key', 'first')->firstOrFail()->definition_hash)->toBe($firstHash)
        ->and($reader->get('catalog.first')['value'])->toBe('First');
});
