<?php

declare(strict_types=1);

namespace Nvl\Settings\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Settings\Casts\SettingValueCast;
use Nvl\Settings\Definitions\Tables\SettingsTables;
use Nvl\Settings\Enums\SettingType;

/**
 * Persisted setting definition fallback and optional custom value.
 *
 * @property string $id
 * @property string $namespace
 * @property string $scope
 * @property string $key
 * @property SettingType $type
 * @property mixed $value
 * @property bool $has_override
 * @property mixed $fallback
 * @property array<string, mixed>|null $metadata
 * @property string $definition_hash
 * @property int $revision
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property Carbon|null $synced_at
 * @property Carbon|null $orphaned_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Setting extends Model
{
    use HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'revision' => 1,
        'definition_hash' => '',
        'has_override' => false,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'namespace',
        'scope',
        'key',
        'type',
        'value',
        'has_override',
        'fallback',
        'metadata',
        'definition_hash',
        'revision',
        'valid_from',
        'valid_until',
        'synced_at',
        'orphaned_at',
    ];

    /**
     * Resolve the configured settings table.
     */
    public function getTable(): string
    {
        $table = config('settings.storage.table', SettingsTables::Settings);

        return is_string($table) && $table !== '' ? $table : SettingsTables::Settings;
    }

    /**
     * Resolve the configured settings connection.
     */
    public function getConnectionName(): ?string
    {
        $connection = config('settings.storage.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * Return the custom value or synchronized fallback.
     */
    public function resolved(): mixed
    {
        if (! $this->hasActiveOverride()) {
            return $this->fallback;
        }

        return $this->value;
    }

    /**
     * Determine whether a stored override is effective at the current time.
     */
    public function hasActiveOverride(): bool
    {
        return $this->isCustomised()
            && ! ($this->valid_from?->isFuture() ?? false)
            && ! ($this->valid_until?->isPast() ?? false);
    }

    /**
     * Determine whether a custom value is stored.
     */
    public function isCustomised(): bool
    {
        return $this->has_override;
    }

    /**
     * Return the canonical namespace.scope.key identifier.
     */
    public function fullKey(): string
    {
        return implode('.', array_filter(
            [$this->namespace, $this->scope, $this->key],
            static fn (string $segment): bool => $segment !== '',
        ));
    }

    /**
     * Get model casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'value' => SettingValueCast::class,
            'has_override' => 'boolean',
            'fallback' => SettingValueCast::class,
            'metadata' => 'array',
            'revision' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'synced_at' => 'datetime',
            'orphaned_at' => 'datetime',
        ];
    }

    /**
     * Increment optimistic revisions for model-level updates.
     */
    protected static function booted(): void
    {
        self::updating(function (Setting $setting): void {
            if (! $setting->isDirty('revision')) {
                $revision = $setting->getOriginal('revision');
                $setting->revision = (is_int($revision) ? $revision : 0) + 1;
            }
        });
    }
}
