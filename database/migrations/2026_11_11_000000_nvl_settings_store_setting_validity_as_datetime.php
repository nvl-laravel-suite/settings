<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Settings\Definitions\Tables\SettingsTables;
use Nvl\Support\Config\PackageStorage;

/**
 * MySQL and MariaDB convert TIMESTAMP values through the session time zone and end in 2038, so a
 * validity window written as UTC seconds could be rejected. DATETIME stores it verbatim. Other
 * drivers already store these columns without a time zone.
 */
return new class extends Migration
{
    private const array COLUMNS = ['valid_from', 'valid_until'];

    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('settings');
    }

    public function up(): void
    {
        $this->retype('timestamp', storeVerbatim: true);
    }

    /** Refuse to narrow the columns while a stored window lies outside the TIMESTAMP range. */
    public function down(): void
    {
        $schema = $this->schema();
        $table = SettingsTables::get(SettingsTables::Settings);

        if (! $this->applies($schema, $table)) {
            return;
        }

        $outOfRange = DB::connection(PackageStorage::connection('settings'))
            ->table($table)
            ->where('valid_from', '<', '1970-01-02 00:00:00')
            ->orWhere('valid_from', '>', '2038-01-18 00:00:00')
            ->orWhere('valid_until', '<', '1970-01-02 00:00:00')
            ->orWhere('valid_until', '>', '2038-01-18 00:00:00')
            ->exists();

        if ($outOfRange) {
            throw new LogicException('Cannot restore TIMESTAMP validity columns while a stored setting window lies outside 1970-2038.');
        }

        $this->retype('datetime', storeVerbatim: false);
    }

    /** Leave other drivers, an absent or foreign table and already converted columns untouched. */
    private function retype(string $from, bool $storeVerbatim): void
    {
        $schema = $this->schema();
        $table = SettingsTables::get(SettingsTables::Settings);

        if (! $this->applies($schema, $table)) {
            return;
        }

        $columns = array_values(array_filter(
            self::COLUMNS,
            static fn (string $column): bool => $schema->getColumnType($table, $column) === $from,
        ));

        if ($columns === []) {
            return;
        }

        $schema->table($table, static function (Blueprint $blueprint) use ($columns, $storeVerbatim): void {
            foreach ($columns as $column) {
                ($storeVerbatim ? $blueprint->dateTime($column) : $blueprint->timestamp($column))->nullable()->change();
            }
        });
    }

    private function schema(): Builder
    {
        return Schema::connection(PackageStorage::connection('settings'));
    }

    private function applies(Builder $schema, string $table): bool
    {
        return in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            && $schema->hasTable($table)
            && $schema->hasColumns($table, self::COLUMNS);
    }
};
