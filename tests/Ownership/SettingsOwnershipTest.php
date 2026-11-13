<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Queue;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\OptsIntoAdoptedSchemaReuse;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\ConfigOverrideApplier;
use Nvl\Settings\Services\PlatformSettingsReader;
use Nvl\Settings\Services\PlatformSettingsRuntime;
use Nvl\Settings\Services\SettingWriter;
use Nvl\Settings\SettingManager;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Tests\Fixtures\OwnershipActor;
use Nvl\Settings\Tests\Fixtures\OwnershipCompany;
use Nvl\Settings\Tests\OwnershipTestCase;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

uses(OwnershipTestCase::class, OptsIntoAdoptedSchemaReuse::class);

it('isolates the same setting key and retained cache between native owners', function (): void {
    $manager = app(SettingManager::class);
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($manager, $actor): void {
        app(SettingWriter::class)->set('interface.theme', 'dark', $actor, 0);
        expect($manager->get('interface.theme'))->toBe('dark');
    });
    $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, function () use ($manager, $actor): void {
        expect($manager->get('interface.theme'))->toBe('light');
        app(SettingWriter::class)->set('interface.theme', 'light', $actor, 0);
        expect(Setting::query()->count())->toBe(1);
    });
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, fn () => expect($manager->get('interface.theme'))->toBe('dark'));
    expect(fn () => $manager->get('interface.theme'))->toThrow(OwnershipViolation::class);
});

it('projects only central config mapped rows and restores a retained runtime after failure', function (): void {
    app(DefinitionRepository::class)->fake([
        'branding.name' => ['type' => SettingType::Text, 'default' => 'Default', 'tenant_override' => true],
    ]);
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, fn () => app(SettingWriter::class)->set('branding.name', 'Owner private', $actor, 0));
    app(DefinitionRepository::class)->fake([
        'branding.name' => ['type' => SettingType::Text, 'default' => 'Default', 'overrides' => 'app.name'],
    ]);
    $runner->central('settings-config', $actor, fn () => app(SettingWriter::class)->set('branding.name', 'Central title', $actor, 0));
    expect(app(PlatformSettingsReader::class)->records()->pluck('value')->all())->toBe(['Central title']);
    config(['nvl-settings.overrides.enabled' => true, 'app.name' => 'Before']);
    $runtime = app(PlatformSettingsRuntime::class);
    expect(fn () => $runtime->run(function (): void {
        expect(config('app.name'))->toBe('Central title');
        throw new RuntimeException('callback failed');
    }))->toThrow(RuntimeException::class, 'callback failed');
    expect(config('app.name'))->toBe('Before');
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, fn () => expect(fn () => app(ConfigOverrideApplier::class)->apply())->toThrow(OwnershipViolation::class));
});

it('quarantines a job missing native ownership without disturbing the enclosing settings projection', function (): void {
    app(DefinitionRepository::class)->fake([
        'branding.name' => ['type' => SettingType::Text, 'default' => 'Default', 'overrides' => 'app.name'],
    ]);
    $actor = OwnershipActor::query()->firstOrFail();
    $runner = app(OwnershipRunner::class);
    $runner->central('settings-config', $actor, fn () => app(SettingWriter::class)->set('branding.name', 'Central title', $actor, 0));
    config(['nvl-settings.overrides.enabled' => true, 'app.name' => 'Before']);

    $payload = $runner->run(
        OwnershipCompany::query()->findOrFail(7),
        $actor,
        static fn (): string => (new ReflectionMethod(Queue::connection('sync'), 'createPayload'))
            ->invoke(Queue::connection('sync'), new SettingsQuarantineProbeJob(OwnershipJobEnvelope::capture(app(OwnershipContext::class))), 'default'),
    );
    $wire = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    unset($wire['data']['nvl_ownership']);

    app(PlatformSettingsRuntime::class)->run(static function () use ($wire): void {
        expect(config('app.name'))->toBe('Central title');
        expect(fn () => (new SyncJob(app(), json_encode($wire, JSON_THROW_ON_ERROR), 'sync', 'default'))->fire())
            ->toThrow(OwnershipViolation::class);
        expect(config('app.name'))->toBe('Central title');
    });

    expect(config('app.name'))->toBe('Before')
        ->and(SettingsQuarantineProbeJob::$handled)->toBeFalse();
});

final class SettingsQuarantineProbeJob implements OwnershipQueuedJob, ShouldQueue
{
    public static bool $handled = false;

    public function __construct(private readonly OwnershipJobEnvelope $envelope) {}

    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->envelope;
    }

    public function handle(): void
    {
        self::$handled = true;
    }
}
