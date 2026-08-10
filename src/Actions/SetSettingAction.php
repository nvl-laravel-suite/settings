<?php

declare(strict_types=1);

namespace Nvl\Settings\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Nvl\Settings\Contracts\SettingsAuditContextProvider;
use Nvl\Settings\Data\SettingMutationData;
use Nvl\Settings\Data\SettingValueData;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Events\SettingChanged;
use Nvl\Settings\Exceptions\StaleSettingVersionException;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\SettingCache;
use Nvl\Settings\Services\SettingValueValidator;
use Nvl\Settings\Support\DefinitionRepository;
use Spatie\LaravelData\Optional;

/**
 * Validates and persists one optimistic runtime override.
 */
final readonly class SetSettingAction
{
    /**
     * Create the optimistic setting mutation action.
     */
    public function __construct(
        private DefinitionRepository $definitions,
        private SettingCache $cache,
        private SettingValueValidator $values,
        private SettingsAuditContextProvider $auditContext,
    ) {}

    /**
     * Persist one validated optimistic runtime override.
     *
     * @throws ValidationException
     */
    public function execute(SettingMutationData $data): SettingValueData
    {
        $definition = $this->definitions->get($data->key);
        $this->values->validate($definition, $data->value);
        $providedValidFrom = $this->parseValidity('validFrom', $data->validFrom);
        $providedValidUntil = $this->parseValidity('validUntil', $data->validUntil);
        $serializedValue = $definition->type->serialize($data->value);
        $serializedFallback = $definition->type->serialize($definition->default);
        $serializedMetadata = json_encode($definition->metadata, JSON_THROW_ON_ERROR);
        $definitionHash = $definition->hash();

        if ($definition->overrides !== null
            && ($providedValidFrom instanceof CarbonImmutable
                || $providedValidUntil instanceof CarbonImmutable)) {
            throw ValidationException::withMessages([
                'validFrom' => [
                    'Configuration overrides cannot use scheduled validity windows.',
                ],
            ]);
        }

        $model = new Setting;
        $connection = DB::connection($model->getConnectionName());
        $setting = $connection->transaction(function () use (
            $connection,
            $data,
            $definition,
            $definitionHash,
            $providedValidFrom,
            $providedValidUntil,
            $serializedFallback,
            $serializedMetadata,
            $serializedValue,
        ): Setting {
            $setting = Setting::query()->where([
                'namespace' => $definition->namespace,
                'scope' => $definition->scope,
                'key' => $definition->key,
            ])->lockForUpdate()->first();
            $created = false;
            $validFrom = $data->validFrom instanceof Optional
                ? $setting?->valid_from
                : ($providedValidFrom instanceof CarbonInterface
                    ? $providedValidFrom
                    : null);
            $validUntil = $data->validUntil instanceof Optional
                ? $setting?->valid_until
                : ($providedValidUntil instanceof CarbonInterface
                    ? $providedValidUntil
                    : null);

            if ($definition->overrides !== null) {
                $validFrom = null;
                $validUntil = null;
            }

            $this->validateWindow($validFrom, $validUntil);

            if (! $setting instanceof Setting) {
                if ($data->expectedRevision !== null && $data->expectedRevision !== 0) {
                    throw StaleSettingVersionException::forKey($data->key);
                }

                $now = now();
                $inserted = Setting::query()->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'namespace' => $definition->namespace,
                    'scope' => $definition->scope,
                    'key' => $definition->key,
                    'type' => $definition->type->value,
                    'value' => $serializedValue,
                    'has_override' => true,
                    'fallback' => $serializedFallback,
                    'metadata' => $serializedMetadata,
                    'definition_hash' => $definitionHash,
                    'revision' => 1,
                    'valid_from' => $validFrom,
                    'valid_until' => $validUntil,
                    'synced_at' => $now,
                    'orphaned_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $setting = Setting::query()->where([
                    'namespace' => $definition->namespace,
                    'scope' => $definition->scope,
                    'key' => $definition->key,
                ])->lockForUpdate()->firstOrFail();

                if ($inserted === 0 && $data->expectedRevision === 0) {
                    throw StaleSettingVersionException::forKey($data->key);
                }

                $created = $inserted === 1;
            }

            if ($data->expectedRevision !== null
                && $setting->revision !== $data->expectedRevision
                && ! ($data->expectedRevision === 0 && $created)) {
                throw StaleSettingVersionException::forKey($data->key);
            }

            $changed = $created;

            if (! $created) {
                $setting->fill([
                    'namespace' => $definition->namespace,
                    'scope' => $definition->scope,
                    'key' => $definition->key,
                    'type' => $definition->type,
                    'value' => $data->value,
                    'has_override' => true,
                    'fallback' => $definition->default,
                    'metadata' => $definition->metadata,
                    'definition_hash' => $definitionHash,
                    'orphaned_at' => null,
                    'valid_from' => $validFrom,
                    'valid_until' => $validUntil,
                ]);

                if ($setting->isDirty()) {
                    $setting->synced_at = now();
                    $setting->save();
                    $setting->refresh();
                    $changed = true;
                }
            }

            if ($changed) {
                $this->cache->flushAfterCommit();
                $id = $setting->id;
                $key = $setting->fullKey();
                $revision = $setting->revision;
                $context = $this->auditContext->current();
                $connection->afterCommit(static function () use ($context, $id, $key, $revision): void {
                    SettingChanged::dispatch($id, $key, $revision, 'set', $context);
                });
            }

            return $setting;
        });

        return SettingValueData::fromModel($setting);
    }

    /**
     * Parse one optional strict validity timestamp.
     */
    private function parseValidity(
        string $attribute,
        string|Optional|null $value,
    ): CarbonImmutable|Optional|null {
        if ($value instanceof Optional || $value === null) {
            return $value;
        }

        try {
            $parsed = SettingType::DateTime->deserialize($value);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                $attribute => [$exception->getMessage()],
            ]);
        }

        if (! $parsed instanceof CarbonImmutable) {
            throw ValidationException::withMessages([
                $attribute => ['The validity timestamp is invalid.'],
            ]);
        }

        if ($parsed->format('u') !== '000000') {
            throw ValidationException::withMessages([
                $attribute => [
                    'Validity timestamps require whole-second precision.',
                ],
            ]);
        }

        return $parsed;
    }

    /**
     * Ensure an effective validity window is ordered.
     */
    private function validateWindow(
        ?CarbonInterface $validFrom,
        ?CarbonInterface $validUntil,
    ): void {
        if ($validFrom instanceof CarbonInterface
            && $validUntil instanceof CarbonInterface
            && ! $validUntil->isAfter($validFrom)) {
            throw ValidationException::withMessages([
                'validUntil' => ['The validity end must be after the validity start.'],
            ]);
        }
    }
}
