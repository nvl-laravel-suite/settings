<?php

declare(strict_types=1);

namespace Nvl\Settings\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Settings\Models\Setting;

/**
 * Builds Setting fixture rows and their declared package parents.
 *
 * @extends Factory<Setting>
 *
 * @api
 */
final class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * Prepare native parent and owner facts after Laravel expands relationships.
     *
     * @internal
     */
    public function configure(): static
    {
        $expandRelationships = true;

        return $this->state(function () use (&$expandRelationships): array {
            $expandRelationships = $this->expandRelationships;

            return [];
        })->afterMaking(function (Setting $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            FactoryGuard::root($model, 'settings.values');
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<Setting>, mixed>
     */
    public function definition(): array
    {
        return [
            'namespace' => 'factory',
            'scope' => 'global',
            'key' => $this->faker->unique()->slug(3),
            'type' => 'string',
            'value' => $this->faker->word(),
            'definition_hash' => hash('sha256', $this->faker->uuid()),
            'revision' => 1,
        ];
    }
}
