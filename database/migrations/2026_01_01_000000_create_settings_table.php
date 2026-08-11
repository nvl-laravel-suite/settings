<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Settings\Definitions\Tables\SettingsTables;

return new class extends Migration
{
    /**
     * Create the complete clean-install Settings schema.
     */
    public function up(): void
    {
        $configuredConnection = config('settings.storage.connection');
        $connection = is_string($configuredConnection) && $configuredConnection !== ''
            ? $configuredConnection
            : null;
        $configuredTable = config('settings.storage.table', SettingsTables::Settings);
        $tableName = is_string($configuredTable) && $configuredTable !== ''
            ? $configuredTable
            : SettingsTables::Settings;
        $schema = Schema::connection($connection);

        if ($schema->hasTable($tableName)) {
            return;
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
        $configuredConnection = config('settings.storage.connection');
        $connection = is_string($configuredConnection) && $configuredConnection !== ''
            ? $configuredConnection
            : null;
        $configuredTable = config('settings.storage.table', SettingsTables::Settings);
        $tableName = is_string($configuredTable) && $configuredTable !== ''
            ? $configuredTable
            : SettingsTables::Settings;

        Schema::connection($connection)->dropIfExists($tableName);
    }
};
