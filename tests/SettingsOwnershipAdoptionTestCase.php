<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests;

use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Tests\Fixtures\OwnershipCompany;

/** Seeds actual pre-adoption rows whose dispositions must be explicitly reviewed. */
abstract class SettingsOwnershipAdoptionTestCase extends OwnershipTestCase
{
    /** @return list<OwnershipMapping> */
    protected function reviewedMappings(): array
    {
        $central = 'a0000000-0000-4000-8000-000000000001';
        $owned = 'a0000000-0000-4000-8000-000000000002';
        foreach ([$central => 'brand', $owned => 'private'] as $id => $key) {
            DB::table((new Setting)->getTable())->insert([
                'id' => $id, 'namespace' => 'branding', 'scope' => '', 'key' => $key,
                'type' => 'string', 'value' => 'reviewed', 'fallback' => 'default',
                'has_override' => true, 'definition_hash' => str_repeat('a', 64),
                'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $owner = $this->app->make(OwnerIdentityResolver::class)->reference(OwnershipCompany::query()->findOrFail(7));

        return [new OwnershipMapping('settings.values', $central, null), new OwnershipMapping('settings.values', $owned, $owner)];
    }
}
