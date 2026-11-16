<?php

declare(strict_types=1);

namespace Nvl\Settings\Adoption;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Enums\SettingAbility;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\SettingModelRegistry;
use Nvl\Settings\Services\SettingsAdoptionManifest;
use Nvl\Settings\Services\SettingsAdoptionMapping;
use Nvl\Support\Adoption\AdoptionAdapter;
use Nvl\Support\Adoption\AdoptionBatch;
use Nvl\Support\Adoption\AdoptionException;
use Nvl\Support\Adoption\AdoptionPlan;
use Nvl\Support\Adoption\AdoptionStep;
use Nvl\Support\Adoption\AdoptionVerification;
use Nvl\Support\Enums\SystemActor;

/**
 * Adopts staged legacy settings overrides through `nvl:adopt` in checkpointed batches keyed by source (scope, key).
 *
 * Options are the same manifest `SettingsAdopter` takes. Each batch writes through the canonical writer at the
 * reviewed expected revision, skips overrides that already match, and never overwrites a row that drifted.
 *
 * @internal
 *
 * @phpstan-import-type Prepared from SettingsAdoptionMapping
 * @phpstan-import-type Source from SettingsAdoptionMapping
 */
final class SettingsAdoptionAdapter extends AdoptionAdapter
{
    public const string Step = 'overrides';

    private const string Version = '1';

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        Authenticatable|SystemActor $actor,
        array $options,
        private readonly SettingsAdoptionMapping $mapping,
        private readonly SettingsAdoptionManifest $manifests,
        private readonly SettingsAuthorization $authorization,
    ) {
        parent::__construct($actor, $options);
    }

    public function connection(): Connection
    {
        return SettingModelRegistry::newInstance()->getConnection();
    }

    public function authorize(string $phase): void
    {
        $ability = in_array($phase, ['apply', 'activate'], true) ? SettingAbility::Set : SettingAbility::View;
        foreach ($this->manifests->normalize($this->options)['key_replacements'] as $targetKey) {
            $this->authorization->authorize($this->actor, $ability, $targetKey);
        }
    }

    public function plan(): AdoptionPlan
    {
        $source = $this->mapping->source($this->options);
        $scan = $this->mapping->scan($source);
        $revisions = $source['manifest']['expected_revisions'];
        [, , , $collisions] = $this->mapping->plannedWrites($scan['prepared']);
        foreach ($scan['prepared'] as $targetKey => $row) {
            $expected = $revisions[$targetKey] ?? null;
            $record = $this->mapping->targetRecord($targetKey);
            if ($expected === null) {
                $collisions[] = "Settings adoption target [{$targetKey}] has no reviewed expected revision.";
            } elseif ($record instanceof Setting && $row['source'] === null && ! $this->mapping->matches($targetKey, $record, $row) && $record->revision !== $expected) {
                $collisions[] = "Settings adoption target [{$targetKey}] differs from its source at revision {$record->revision}; the reviewed revision is {$expected}.";
            } elseif (! $record instanceof Setting && $expected !== 0) {
                $collisions[] = "Settings adoption target [{$targetKey}] does not exist; the reviewed revision is {$expected}.";
            }
        }
        $origin = $source['origin'];
        $target = SettingModelRegistry::newInstance();

        return new AdoptionPlan(
            package: 'settings',
            adapterVersion: self::Version,
            fingerprint: AdoptionPlan::digest(['mapping' => self::Version, 'source' => $scan['fingerprint'], 'expected_revisions' => $revisions]),
            source: ['connection' => $origin->getName(), 'table' => $origin->getTablePrefix().$source['manifest']['source_table']],
            destination: ['connection' => $target->getConnection()->getName(), 'table' => $target->getConnection()->getTablePrefix().$target->getTable()],
            optionsDigest: AdoptionPlan::digest($this->options),
            steps: [new AdoptionStep(self::Step, [], $this->cursorColumns($source))],
            expected: [self::Step => $scan['count']],
            collisions: array_values(array_unique($collisions)),
        );
    }

    public function nextBatch(AdoptionStep $step, ?array $cursor, int $limit): AdoptionBatch
    {
        $source = $this->mapping->source($this->options);
        $rows = $step->page($this->mapping->query($source), $cursor, $limit + 1)->get()->all();
        $page = array_slice($rows, 0, $limit);
        $mapped = [];
        foreach ($page as $row) {
            $identity = $this->mapping->identify($source, $row);
            $override = $this->mapping->map($source, $identity['identity'], $row);
            $mapped[] = $override === null ? ['identity' => $identity['identity'], 'target' => null] : [
                'identity' => $identity['identity'], 'target' => $override['target'], 'value' => $override['row']['value'],
                'source' => $override['row']['source'], 'valid_from' => $override['row']['valid_from']?->format('Y-m-d H:i:s'),
                'verify_window' => $override['row']['verify_window'],
            ];
        }
        $last = $page === [] ? null : $page[array_key_last($page)];

        return new AdoptionBatch($step->id, $cursor, $last === null ? $cursor : $step->cursorFrom($last), $mapped, count($rows) <= $limit);
    }

    public function writeBatch(AdoptionBatch $batch): ?string
    {
        $revisions = $this->manifests->normalize($this->options)['expected_revisions'];
        $written = [];
        foreach ($batch->rows as $row) {
            $targetKey = $row['target'];
            if (! is_string($targetKey)) {
                continue;
            }
            $prepared = $this->prepared($row);
            $record = $this->mapping->targetRecord($targetKey);
            if (! $record instanceof Setting || ! $this->mapping->matches($targetKey, $record, $prepared)) {
                $this->mapping->write($targetKey, $prepared, $this->actor, $revisions[$targetKey]
                    ?? throw new AdoptionException("Settings adoption target [{$targetKey}] has no reviewed expected revision."));
                $record = $this->mapping->targetRecord($targetKey) ?? throw new AdoptionException("Settings adoption target [{$targetKey}] was not written.");
            }
            $written[$targetKey] = $record->revision;
        }

        return $written === [] ? null : AdoptionPlan::digest($written);
    }

    public function verify(AdoptionPlan $plan): AdoptionVerification
    {
        $scan = $this->mapping->scan($this->mapping->source($this->options));
        $missing = $changed = 0;
        $state = [];
        foreach ($scan['prepared'] as $targetKey => $row) {
            $record = $this->mapping->targetRecord($targetKey);
            if (! $record instanceof Setting) {
                $missing++;

                continue;
            }
            if (! $this->mapping->matches($targetKey, $record, $row)) {
                $changed++;
            }
            $state[$targetKey] = [$record->id, $record->revision];
        }
        ksort($state, SORT_STRING);

        return new AdoptionVerification($missing, 0, $changed, AdoptionPlan::digest($state));
    }

    /**
     * Keyset the source by scope, when present, and key.
     *
     * @param  Source  $source
     * @return list<string>
     */
    private function cursorColumns(array $source): array
    {
        $manifest = $source['manifest'];

        return $manifest['scope_column'] === null ? [$manifest['key_column']] : [$manifest['scope_column'], $manifest['key_column']];
    }

    /**
     * Restore a mapped batch row to the writer's prepared shape.
     *
     * @param  array<string, mixed>  $row
     * @return Prepared
     */
    private function prepared(array $row): array
    {
        /** @var array{id:string,created_at:?string,updated_at:?string}|null $source */
        $source = $row['source'];

        return [
            'value' => $row['value'],
            'source' => $source,
            'valid_from' => $this->mapping->timestamp($row['valid_from']),
            'verify_window' => $row['verify_window'] === true,
        ];
    }
}
