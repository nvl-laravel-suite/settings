<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Nvl\Settings\Models\Setting;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;

/** Creates a raw serialized setting inside the canonical writer and a race-safe savepoint. @internal */
final readonly class SettingCreator
{
    /** Keep persistence independent of the optional enforcing runtime. */
    public function __construct(private OwnershipWriter $writer) {}

    /** @param array<string, mixed> $attributes */
    public function insert(array $attributes): int
    {
        try {
            $this->writer->create(Setting::class, static function (array $ownership) use ($attributes): Setting {
                $setting = new Setting;
                $setting->setRawAttributes([...$attributes, ...$ownership]);
                $setting->save();

                return $setting;
            });

            return 1;
        } catch (UniqueConstraintViolationException) {
            return 0;
        }
    }
}
