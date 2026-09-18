<?php

declare(strict_types=1);

namespace Nvl\Settings\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Nvl\Settings\Models\Setting;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionMappings;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Owns bounded Settings ownership expansion and reviewed legacy assignment. */
final readonly class SettingsAdoptionAdapter implements TenantAdoptionAdapter
{
    /** Create the Settings adopter. */
    public function __construct(
        private Migrator $migrator,
        private TenantAdoptionMappings $mappings,
    ) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['settings.values'];
    }

    /** Install the separately selected ownership schema. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->connection($plan);
        $this->migrator->usingConnection(
            $plan->connection,
            fn () => $this->migrator->run([dirname(__DIR__, 2).'/database/tenancy-migrations'], ['force' => true]),
        );
    }

    /** Apply reviewed tenant assignments while unmapped rows retain platform disposition. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $assignments = $this->mappings->assignments($plan, 'settings.values', $cursor, $limit);
        $connection = $this->connection($plan);

        $connection->transaction(function () use ($assignments, $connection): void {
            foreach ($assignments as $assignment) {
                $connection->table((new Setting)->getTable())
                    ->where('id', $assignment->recordId)
                    ->update([
                        'tenant_id' => $assignment->tenantId->value,
                        'ownership_key' => 'tenant:'.$assignment->tenantId->value,
                    ]);
            }
        });

        if ($assignments === []) {
            return new TenantBackfillResult(null, 0);
        }

        return new TenantBackfillResult(
            $assignments[array_key_last($assignments)]->recordId,
            count($assignments),
        );
    }

    /**
     * Verify discriminator consistency and identity uniqueness.
     *
     * @phpstan-impure
     */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->connection($plan);
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

    /** Resolve canonical Settings storage on the adoption connection. */
    private function connection(TenantAdoptionPlan $plan): Connection
    {
        $connection = (new Setting)->setConnection($plan->connection)->getConnection();
        if ($connection->getName() !== $plan->connection) {
            throw new TenantBoundaryViolation('Settings adoption requires its canonical storage connection.');
        }

        return $connection;
    }
}
