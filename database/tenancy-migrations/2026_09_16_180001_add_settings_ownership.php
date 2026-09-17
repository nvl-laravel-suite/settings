<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Settings\Models\Setting;

return new class extends Migration
{
    /** Expand Settings identity from a global key to a mixed ownership key. */
    public function up(): void
    {
        $model = new Setting;
        $schema = $model->getConnection()->getSchemaBuilder();
        $tableName = $model->getTable();

        if (! $schema->hasColumn($tableName, 'tenant_id')) {
            $schema->table($tableName, static function (Blueprint $table): void {
                $table->uuid('tenant_id')->nullable();
            });
        }
        if (! $schema->hasColumn($tableName, 'ownership_key')) {
            $schema->table($tableName, static function (Blueprint $table): void {
                $table->string('ownership_key', 43)->default('platform');
            });
        }

        $schema->table($tableName, static function (Blueprint $table): void {
            $table->dropUnique(['namespace', 'scope', 'key']);
            $table->unique(['ownership_key', 'namespace', 'scope', 'key'], 'settings_ownership_identity_unique');
            $table->index(['tenant_id', 'namespace', 'scope', 'key'], 'settings_tenant_lookup_idx');
        });
    }

    /** Preserve adopted ownership evidence; rollback requires a reviewed recovery workflow. */
    public function down(): void {}
};
