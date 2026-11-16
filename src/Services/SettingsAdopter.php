<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Plans and applies a reconciled import from one staged legacy settings table.
 *
 * @api
 *
 * @phpstan-import-type NormalizedManifest from SettingsAdoptionManifest
 *
 * @phpstan-type AdoptionResult array{mode:string,source_table:string,schema_fingerprint:string,fingerprint:string,orphans:list<array{scope:?string,key:string}>,conflicts:list<string>,reconciliation:array{expected:int,source:int,mapped:int,matched:int,created:int,updated:int,unchanged:int}}
 */
final readonly class SettingsAdopter
{
    private SettingsAdoptionMapping $mapping;

    /**
     * Create the Settings adoption action.
     */
    public function __construct(
        SettingsAdoptionManifest $manifests,
        DefinitionRepository $definitions,
        SettingValueValidator $values,
        SettingWriter $writer,
        OwnershipBoundary $boundary,
    ) {
        $this->mapping = new SettingsAdoptionMapping($manifests, $definitions, $values, $writer, $boundary);
    }

    /**
     * Validate every source row and optionally persist all mapped overrides atomically.
     *
     * @param  array<array-key, mixed>  $manifest
     * @return AdoptionResult
     */
    public function execute(array $manifest, bool $apply = false, Authenticatable|SystemActor|null $actor = null): array
    {
        return $this->run($manifest, $apply ? 'apply' : 'plan', $actor);
    }

    /**
     * Reconcile the committed target against a reviewed fingerprint before cutover.
     *
     * @param  array<array-key, mixed>  $manifest
     * @return AdoptionResult
     */
    public function verify(array $manifest): array
    {
        if (SettingModelRegistry::newInstance()->getConnection()->transactionLevel() !== 0) {
            throw new InvalidArgumentException('Settings adoption verification requires a committed target outside a transaction.');
        }

        return $this->run($manifest, 'verify', null);
    }

    /**
     * @param  array<array-key,mixed>  $manifest
     * @param  'plan'|'apply'|'verify'  $mode
     * @return AdoptionResult
     */
    private function run(array $manifest, string $mode, Authenticatable|SystemActor|null $actor): array
    {
        $source = $this->mapping->source($manifest);
        $normalized = $source['manifest'];
        $scan = $this->mapping->scan($source);
        $prepared = $scan['prepared'];
        $fingerprint = $scan['fingerprint'];
        $requiresFingerprint = $mode === 'verify' || $normalized['scope_column'] !== null || $normalized['valid_from_column'] !== null
            || $normalized['preserve_identity'] || $normalized['value_encoding'] !== 'target_codec' || $normalized['orphan_policy'] !== 'reject';
        if ($mode !== 'plan' && ($requiresFingerprint || $normalized['fingerprint'] !== null)
            && ! hash_equals($fingerprint, $normalized['fingerprint'] ?? '')) {
            throw new InvalidArgumentException('A matching reviewed Settings adoption fingerprint is required.');
        }

        [$created, $updated, $unchanged, $conflicts] = $this->mapping->plannedWrites($prepared);
        if ($mode !== 'plan' && $conflicts !== []) {
            throw new InvalidArgumentException($conflicts[0]);
        }
        $matched = 0;
        if ($mode === 'apply') {
            if ($actor === null || count($normalized['expected_revisions']) !== count($prepared)
                || array_diff(array_keys($prepared), array_keys($normalized['expected_revisions'])) !== []) {
                throw new InvalidArgumentException('Settings adoption apply requires an explicit actor and expected revision for every mapped target.');
            }
            $matched = SettingModelRegistry::newInstance()->getConnection()->transaction(function () use ($actor, $normalized, $prepared): int {
                foreach ($prepared as $targetKey => $row) {
                    $this->mapping->write($targetKey, $row, $actor, $normalized['expected_revisions'][$targetKey]);
                }

                return $this->mapping->reconcile($prepared);
            });
        } elseif ($mode === 'verify') {
            $matched = $this->mapping->reconcile($prepared);
        }

        return [
            'mode' => $mode,
            'source_table' => $normalized['source_table'],
            'schema_fingerprint' => $source['schema_fingerprint'],
            'fingerprint' => $fingerprint,
            'orphans' => $scan['orphans'],
            'conflicts' => $conflicts,
            'reconciliation' => [
                'expected' => $normalized['expected_count'],
                'source' => $scan['count'],
                'mapped' => count($prepared),
                'matched' => $matched,
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
            ],
        ];
    }
}
