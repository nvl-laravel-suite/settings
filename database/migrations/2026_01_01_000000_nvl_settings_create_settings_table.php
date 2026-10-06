<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Settings\Definitions\Tables\SettingsTables;
use Nvl\Support\Config\PackageStorage;

return new class extends Migration
{
    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('settings');
    }

    /**
     * Create the complete clean-install Settings schema.
     */
    public function up(): void
    {
        $connection = PackageStorage::connection('settings');
        $configuredTable = config('settings.storage.table', SettingsTables::get(SettingsTables::Settings));
        $tableName = is_string($configuredTable) && $configuredTable !== ''
            ? $configuredTable
            : SettingsTables::get(SettingsTables::Settings);
        $schema = Schema::connection($connection);

        if ($schema->hasTable($tableName)) {
            throw new LogicException('Existing package table is not owned by this migration. Run nvl:doctor --strict and use nvl:schema:upgrade for a verified legacy installation.');
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('namespace', 100);
            $table->string('scope', 100)->default('');
            $table->string('key', 100);
            $table->string('type', 32);
            $table->longText('value')->nullable();
            $table->boolean('has_override')->default(false);
            $table->longText('fallback')->nullable();
            $table->json('metadata')->nullable();
            $table->char('definition_hash', 64);
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('orphaned_at')->nullable();
            $table->timestamps();

            $table->unique(['namespace', 'scope', 'key']);
            $table->index(['namespace', 'scope']);
            $table->index(['orphaned_at', 'synced_at']);
            $table->index(['valid_from', 'valid_until']);
        });
    }

    /**
     * Remove the clean-install Settings schema.
     */
    public function down(): void
    {
        $connection = PackageStorage::connection('settings');
        $configuredTable = config('settings.storage.table', SettingsTables::get(SettingsTables::Settings));
        $tableName = is_string($configuredTable) && $configuredTable !== ''
            ? $configuredTable
            : SettingsTables::get(SettingsTables::Settings);

        Schema::connection($connection)->dropIfExists($tableName);
    }
};
