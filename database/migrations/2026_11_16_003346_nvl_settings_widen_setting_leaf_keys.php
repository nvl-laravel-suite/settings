<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Settings\Definitions\Tables\SettingsTables;
use Nvl\Support\Config\PackageStorage;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return PackageStorage::connection('settings');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table(SettingsTables::get(SettingsTables::Settings), static function (Blueprint $table): void {
            $table->string('key', 255)->change();
        });
    }

    public function down(): void
    {
        $table = SettingsTables::get(SettingsTables::Settings);
        $schema = Schema::connection($this->getConnection());
        if (! $schema->hasTable($table)) {
            return;
        }
        if (DB::connection($this->getConnection())->table($table)->where('key', 'like', str_repeat('_', 101).'%')->exists()) {
            throw new LogicException('Remove or rename setting leaves longer than 100 characters before rolling back this migration.');
        }
        $schema->table($table, static function (Blueprint $table): void {
            $table->string('key', 100)->change();
        });
    }
};
