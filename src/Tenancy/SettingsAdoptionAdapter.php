<?php

declare(strict_types=1);

namespace Nvl\Settings\Tenancy;

use Illuminate\Database\Migrations\Migrator;
use Nvl\Settings\Models\Setting;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionBoundary;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Owns bounded Settings ownership expansion and reviewed legacy assignment. */
final readonly class SettingsAdoptionAdapter implements TenantAdoptionAdapter
{
    /** Create the Settings adopter. */
    public function __construct(
        private Migrator $migrator,
        private TenantAdoptionBoundary $adoption,
    ) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['settings.values'];
    }

    /** Install the separately selected ownership schema. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->adoption->connection($plan, 'settings.values');
        $this->migrator->usingConnection(
            $plan->connection,
            fn () => $this->migrator->run([dirname(__DIR__, 2).'/database/tenancy-migrations'], ['force' => true]),
        );
    }

    /** Apply reviewed tenant assignments while unmapped rows retain platform disposition. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $assignments = $this->adoption->assignments($plan, 'settings.values', $cursor, $limit);
        $connection = $this->adoption->connection($plan, 'settings.values');

        $connection->transaction(function () use ($assignments, $connection): void {
            foreach ($assignments as $assignment) {
                $connection->table((new Setting)->getTable())
                    ->where('id', $assignment->recordId)
                    ->update($this->adoption->ownership($assignment, 'settings.values'));
            }
        });

        return $this->adoption->result($assignments);
    }

    /**
     * Verify discriminator consistency and identity uniqueness.
     *
     * @phpstan-impure
     */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->adoption->connection($plan, 'settings.values');
        $table = (new Setting)->getTable();
        $schema = $connection->getSchemaBuilder();
        $errors = [];

        if (! $schema->hasColumn($table, 'tenant_id') || ! $schema->hasColumn($table, 'ownership_key')) {
            $errors[] = 'settings.ownership_schema';
        }
        foreach ($connection->table($table)->select(['id', 'tenant_id', 'ownership_key'])->orderBy('id')->cursor() as $row) {
            $tenantId = is_string($row->tenant_id) ? $row->tenant_id : null;
            $expected = $tenantId === null ? 'platform' : 'tenant:'.$tenantId;
            if (! is_string($row->ownership_key) || ! hash_equals($expected, $row->ownership_key)) {
                $errors[] = 'settings.ownership_discriminator:'.(is_string($row->id) || is_int($row->id) ? (string) $row->id : 'unknown');
            }
            if (count($errors) >= 100) {
                break;
            }
        }

        return new TenantVerification($errors);
    }

    /** Recheck evidence before installation markers become active. */
    public function activate(TenantAdoptionPlan $plan): void
    {
        if (! $this->verify($plan)->passed()) {
            throw new TenantBoundaryViolation('Settings tenant ownership did not verify.');
        }
    }
}
