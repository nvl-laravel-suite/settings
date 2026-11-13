<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Nvl\Support\Config\PackageStorage;

/** Preserve migration ordering. Supported engines already store validity windows without a time zone. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return PackageStorage::connection('settings');
    }

    public function up(): void {}

    public function down(): void {}
};
