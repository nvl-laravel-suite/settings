# NVL Settings — API and usage

## Quickstart

```sh
composer require nvl/settings:^5.0
php artisan nvl:install settings --dry-run
php artisan nvl:install settings
```

Required NVL dependencies: `nvl/core` (`^5.0`). Register the site.title definition in a configured host Settings source before this call. Definition discovery paths are host-selected; no arbitrary filesystem scan is required.
Review the published common config, select one migration owner, and run schema preflight before existing-table upgrades. The installer does not enable features or run migrations. Follow the detailed installation and capability sections below before invoking a storage/provider operation.

Inject `Nvl\Settings\Contracts\GetSettingContract` in a host service. After supplying the trusted inputs described above, the first public call is:

```php
use Nvl\Settings\Contracts\GetSettingContract;

/** @var GetSettingContract $capability */
$result = $capability->execute('site.title');
```

Use the [event catalog](docs/events.md) and [Testing your app](#testing-your-app) below. The suite [getting-started guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/getting-started.md) provides a complete Comments host fixture; package archives retain their own local references.


[← NVL Laravel Suite](https://github.com/nvl-laravel-suite)

For support, [open an issue](https://github.com/nvl-laravel-suite/settings/issues). For vulnerabilities, use
[private reporting](https://github.com/nvl-laravel-suite/settings/security/advisories/new). See [Contributing](CONTRIBUTING.md).

See the [installation and publishing guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/installation.md) for Composer setup, configuration, migration ownership, and agent skills.

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/settings:^5.0` |
| Module identifier | `nvl/settings` |
| PHP namespace | `Nvl\Settings` |
| Service provider | `Nvl\Settings\Providers\SettingsServiceProvider` |
| Configuration | `config/nvl-settings.php` |

A source-defined, typed runtime settings engine for Laravel applications.

## Purpose

`nvl/settings` keeps definitions in source control and stores runtime overrides
plus synchronization metadata in the database. It provides deterministic
discovery, validation, effective-value resolution, caching, optimistic
concurrency, after-commit events, safe Laravel config overrides, and an
optional authorized management API.

It does not provide per-user preferences, secrets management, arbitrary
key/value storage, localized content, tenant ownership, or application UI.

## Requirements and dependency

- PHP 8.4 or newer
- Laravel 12–13
- `nvl/core` for public DTO and generated TypeScript contracts

## Installation

```bash
composer require nvl/settings:^5.0
php artisan migrate
php artisan vendor:publish --tag=nvl-settings-translations
php artisan vendor:publish --tag=nvl-settings-config
```

Package discovery registers `SettingsServiceProvider`. Migrations load
automatically unless `nvl-settings.migrations.enabled` is false.

```bash
php artisan vendor:publish --tag=nvl-settings-skills
```

Choose exactly one migration owner. For automatic vendor loading, leave
`nvl-settings.migrations.enabled=true` and do not publish `nvl-settings-migrations`.
For host-owned migrations, run
`php artisan vendor:publish --tag=nvl-settings-migrations`, set
`nvl-settings.migrations.enabled=false` before the first migration, and maintain
the copied files as application migrations. Never run both sources; Laravel
retimestamps published migrations.

## Define settings

Put `*.settings.php` or `*.settings.json` files in configured discovery paths.
Both formats compile into the same validated definition model.

PHP sources are trusted executable configuration and may use enum and
deterministically stringable or serializable Laravel rule objects. Closures are
rejected because their semantics cannot participate in stable definition
hashes:

```php
use Nvl\Settings\Enums\SettingType;

return [
    'namespace' => 'interface',
    'settings' => [
        'theme' => [
            'type' => SettingType::Enum,
            'default' => 'light',
            'rules' => ['in:light,dark'],
            'description' => 'Default interface theme.',
            'metadata' => ['group' => 'appearance'],
        ],
        'page_size' => [
            'type' => SettingType::Integer,
            'default' => 25,
            'rules' => ['min:1', 'max:100'],
        ],
    ],
];
```

JSON sources use portable `SettingType` values and string validation rules:

```json
{
    "namespace": "catalog",
    "scopes": {
        "listing": {
            "page_size": {
                "type": "int",
                "default": 24,
                "rules": ["min:1", "max:100"],
                "description": "Default catalog page size.",
                "metadata": {
                    "group": "pagination"
                }
            }
        }
    }
}
```

Definition rules always validate the root setting value. Laravel paths such as
`value.*` are therefore not definition rules. For typed JSON collections, use
the first-party root rules in JSON sources:

```json
{
    "namespace": "notifications",
    "settings": {
        "reminder_minutes": {
            "type": "json",
            "default": [5, 15],
            "rules": ["settings_integer_list_between:1,60"]
        },
        "channel_limits": {
            "type": "json",
            "default": {"email": 10, "sms": 5},
            "rules": ["settings_integer_map_between:1,100"]
        }
    }
}
```

Trusted PHP definitions may use the equivalent deterministic rule objects:

```php
use Nvl\Settings\Support\SettingsRules;

'rules' => [SettingsRules::integerListBetween(1, 60)],
```

Definitions may group keys under one level of scopes:

```php
return [
    'namespace' => 'content',
    'scopes' => [
        'listing' => [
            'page_size' => [
                'type' => SettingType::Integer,
                'default' => 25,
            ],
        ],
    ],
];
```

Canonical keys are `namespace.key` or `namespace.scope.key`. A declared
namespace must match the `name.settings.php|json` filename namespace. Discovery
is sorted and duplicate namespaces or keys, malformed files, invalid types,
unsafe segments, invalid rules, and invalid override targets fail explicitly.
Arbitrarily nested canonical keys are intentionally unsupported. During
adoption, flatten segments after the optional scope into one descriptive
snake-case key and record every legacy-to-canonical replacement in the
adoption manifest; for example,
`core.currency.dual_pricing.enabled` may map to
`core.currency.dual_pricing_enabled`.

Configure any number of explicit directories or directory globs:

```php
'discovery' => [
    'paths' => [
        base_path('settings'),
        base_path('domains/*/settings'),
    ],
    'patterns' => ['*.settings.php', '*.settings.json'],
    'recursive' => true,
    'follow_links' => false,
    'maximum_files' => 1000,
    'maximum_file_bytes' => 262144,
    'maximum_json_depth' => 64,
    'cache' => true,
    // null uses bootstrap/cache/nvl-settings.php
    'cache_path' => null,
],
```

Files are sorted deterministically. Real paths must remain below their
configured root; optional link following is limited to targets that remain
inside that root. The scanner limits file count, source bytes, and JSON nesting;
rejects duplicate filename namespaces across all roots; and reports invalid
JSON without entering the synchronization transaction. A source checksum
covers every discovered file by stable namespace and content digest, so moving
an unchanged source tree does not create a false change. A custom cache path
must remain below
`bootstrap/cache`.

Supported types are string, integer, decimal, boolean, enum, date, date-time,
and JSON. Dates use `Y-m-d`; date-times require timezone-aware ISO 8601 input
and are stored in UTC without discarding provided microseconds. Scheduled
validity windows use whole-second precision. Relative dates and permissive
scalar coercion are rejected. Text and enum values must be valid UTF-8. JSON
values must be arrays. Add `nullable` to a definition's rules when a runtime
null is valid.

## Typed Actions

The public action boundary returns Core Data DTOs:

```php
use Nvl\Settings\Contracts\GetSettingContract;
use Nvl\Settings\Contracts\SetSettingContract;
use Nvl\Settings\Data\SettingMutationData;

$current = app(GetSettingContract::class)->execute('interface.theme');

$updated = app(SetSettingContract::class)->execute(
    SettingMutationData::validateAndCreate([
        'key' => 'interface.theme',
        'value' => 'dark',
        'expectedRevision' => $current->revision,
    ]),
);
```

The initial value has revision `0`, so a race-safe first write sends
`expectedRevision: 0`. Existing rows require their exact positive revision.
Omitting the token is supported only by the lower-level programmatic Action;
the management API always requires it.

Scheduled overrides are optional:

```php
SettingMutationData::validateAndCreate([
    'key' => 'campaign.banner',
    'value' => true,
    'expectedRevision' => 0,
    'validFrom' => '2026-11-01T00:00:00+00:00',
    'validUntil' => '2026-12-01T00:00:00+00:00',
]);
```

Before `validFrom` and after `validUntil`, the definition fallback is the
effective value and the DTO source is `definition`. Partial window updates are
validated against the dates already stored on the row.

Available consumer Actions are:

- `GetSettingAction`
- `GetManySettingsAction`
- `ListSettingsAction`
- `SetSettingAction`
- `ResetSettingAction`
- `ValidateSettingsSourcesAction`

Run `nvl:settings:adopt` for reviewed legacy adoption; its implementation Action is internal.

`SettingDefinitionData`, `SettingMutationData`, and `SettingValueData` describe
definitions, writes, and effective values. Effective values include their
source (`definition` or `database`), type, revision, definition hash, and
`hasOverride` state, and orphan state. `hasOverride` is independent from the
payload, so an explicitly stored nullable override remains distinguishable from
reset state.

Set and reset acquire a row lock and reject stale revisions, including
concurrent first writes. `SettingChanged`
contains only identifiers and mutation metadata, never the setting value, and
dispatches after commit. Its `context` snapshot carries optional actor type/id,
request id, IP address, and user agent. The default
`SettingsAuditContextProvider` reads bounded values from Laravel's current
request before commit; applications may replace that contract for their own
actor and correlation model. Canonically equivalent repeat writes are no-ops: they
do not advance the revision, refresh synchronization timestamps, flush the
value cache, or emit `SettingChanged`.

## Setting change subject reference

`SettingChanged` exposes a value-free `subject` alongside its existing ID,
key, revision, operation, and audit context. The stable subject shape is
`type: 'nvl_setting'` plus the setting UUID. It is constructed by the event and
is not an additional dispatch argument.

An application using `nvl/activity` can therefore record the setting mutation
without loading the package model:

```php
use Nvl\Activity\Facades\ActivityLog;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Settings\Events\SettingChanged;

function recordSettingActivity(SettingChanged $event): void
{
    ActivityLog::recordForSubjectReference(
        subject: new ActivitySubjectReference(
            $event->subject->type,
            $event->subject->id,
        ),
        event: $event->operation,
        description: 'settings.changed',
        context: [
            'key' => $event->key,
            'revision' => $event->revision,
        ],
    );
}
```

The event and subject never serialize the setting value. Keep listeners
idempotent because after-commit events may be handled asynchronously.

## Repository convenience API

Applications that do not need mutation result DTOs may depend on
`SettingRepository`:

```php
$settings = app(SettingRepository::class);

$theme = $settings->get('interface.theme');
$settings->set('interface.theme', 'dark');
$settings->setMany([
    'interface.theme' => 'dark',
    'interface.page_size' => 50,
]);
$settings->forget('interface.theme');
```

The `Setting` facade mirrors this contract. Unknown keys, validation failures,
cast errors, and database failures are not swallowed. Definitions own their
fallbacks; `get()` does not accept a caller fallback.

## Synchronize definitions

```bash
php artisan nvl:settings:validate
php artisan nvl:settings:validate --format=json
php artisan nvl:settings:sync --dry-run
php artisan nvl:settings:sync
php artisan nvl:settings:sync --provider=interface
php artisan nvl:settings:sync --prune
php artisan nvl:settings:list --namespace=interface --changed
php artisan nvl:settings:reset interface.theme --dry-run
php artisan nvl:settings:reset interface --force
php artisan nvl:settings:cache
php artisan nvl:settings:clear
php artisan nvl:settings:doctor --strict --format=json
php artisan nvl:settings:adopt storage/adoption/settings.json --format=json
php artisan nvl:settings:adopt storage/adoption/settings.json --apply --format=json
```

`nvl:settings:validate` performs discovery, format parsing, namespace/scope/key
validation, type resolution, default-value validation, rule validation,
duplicate detection, and checksum generation without reading or writing the
settings table.

Validation and synchronization always rescan configured roots instead of
trusting a possibly stale discovery cache. Synchronization updates type,
fallback, metadata, definition hash, and sync
timestamps while preserving runtime overrides when configured. Missing source
definitions follow the configured `orphan`, `delete`, or `ignore` policy.
`--prune` explicitly selects deletion. `nvl:settings:sync` is isolatable; use
`--isolated` on multi-server deployments backed by a shared cache.
Synchronization locks live rows and uses conflict-safe inserts before updating
definition metadata, so a concurrent write cannot be replaced by a stale
pre-transaction snapshot or move a revision backwards.
The dry run exits unsuccessfully when an existing override is incompatible
with its current source definition, making it safe to use as a deployment
gate.

`nvl:settings:cache` validates and atomically replaces the source map used by
runtime reads. Re-run it after files are added, moved, or removed.
`nvl:settings:reset` treats a full key as an exact match; namespace or
namespace/scope prefixes require `--force` when they match more than one
override. Always review dry runs before reset or prune.

## Optional config overrides

Definitions may explicitly target a Laravel config key:

```php
'display_name' => [
    'type' => SettingType::Text,
    'default' => 'Example',
    'overrides' => 'app.name',
],
```

Overrides are disabled by default. When enabled, they apply only after a safe
application boot and schema check. Denied patterns protect environment,
debugging, database, cache, and settings configuration. Workers must restart
after override changes because application configuration is process state.
Mapped definition defaults apply even before synchronization. Scheduled
validity windows are rejected for config-mapped settings because a boot-time
configuration snapshot cannot activate them safely in a long-running process.

## Optional management API

The API is disabled by default:

```php
'management' => [
    'enabled' => true,
    'path' => 'nvl/api/v1/settings',
    'name' => 'nvl.settings.management.',
    'middleware' => ['api', 'auth', 'throttle:60,1'],
    'authorization_ability' => 'manage-settings',
],
```

The path accepts safe slash-separated URI segments. The name is a configurable
route-name prefix and receives a trailing dot automatically.

| Method | Path | Default name | Purpose |
| --- | --- | --- | --- |
| `GET` | `/status` | `nvl.settings.management.status` | Validate source discovery and return sanitized counts/checksum |
| `GET` | `/` | `nvl.settings.management.index` | List definitions and effective values |
| `GET` | `/{key}` | `nvl.settings.management.show` | Inspect one effective value |
| `PUT` | `/{key}` | `nvl.settings.management.update` | Set a validated optimistic override |
| `DELETE` | `/{key}` | `nvl.settings.management.reset` | Reset an override using its expected revision |

`GET /` accepts `namespace`, `scope`, `search`, `page`, and `perPage` (maximum
100). It returns `data.items` plus `data.meta`; only the requested page is read
from storage. `PUT` requires `value` and `expectedRevision` and accepts
`validFrom`/`validUntil`. A first write uses revision `0`; reset requires the
current positive revision.

Unknown keys return `404` with `error.code=unknown_setting`; missing persisted
overrides return `404` with `error.code=setting_override_not_found`; stale
revisions return `409` with `error.code=stale_setting_revision`. All use the
stable `{"error":{"code":"...","message":"..."}}` envelope.

Every request passes `SettingsAuthorization`; the
default implementation fails closed until a Gate ability is configured.
Applications may bind the contract for scope/key-specific policy logic.
The abilities are `status`, `list`, `view`, `set`, and `reset`.

No management UI is included.

## Database, caching, and adoption

The configured table uses UUID primary keys and unique
`namespace/scope/key` identifiers. It stores typed value and fallback JSON,
an explicit `has_override` flag, metadata, definition hash, revision, validity
dates, synchronization and orphan timestamps, plus query indexes for scope,
validity, and sync status.

The cache is optional and stores primitive attribute arrays rather than PHP
objects, making it compatible with Laravel 13's hardened cache deserialization.
Its default key is `nvl:settings:v2`. Invalidation from model saves, deletes,
canonical Actions, and synchronization runs only after the outer database
transaction commits. Reads inside a transaction on the settings connection bypass the shared cache, so they see that transaction’s writes without publishing uncommitted values. Cache failures and database outages are not converted
into defaults.

For an existing table, disable automatic migrations during assessment and run:

```bash
php artisan nvl:settings:doctor --strict --format=json
```

The doctor checks the configured connection/table, required v1 columns,
identifier type, indexes, duplicate identities, uncached definition discovery,
cache freshness, canonical stored value encodings, and management route
security without mutating state. Its `schema.compatibility` check explicitly
distinguishes the canonical package schema from a same-name legacy table.
Package migrations create the complete clean-install schema and do not mutate
an unrelated existing table.

Adopt an established typed key/value store through a staging table and a
versioned manifest. Dry-run is the default and performs no writes. It requires
one explicit replacement for every source row, resolves every target against a
source definition, decodes and validates values through the target type, checks
the declared count, and rejects unknown, duplicated, missing, or colliding
keys. `--apply` writes the complete validated set atomically and reconciles the
target count. It is safe to repeat; canonical no-op writes keep their revision.

```json
{
    "version": 1,
    "source_connection": "sqlite",
    "source_table": "legacy_settings",
    "key_column": "key",
    "value_column": "value",
    "expected_count": 2,
    "key_replacements": {
        "core.currency.dual_pricing.enabled": "core.currency.dual_pricing_enabled",
        "notifications.reminders.minutes": "notifications.reminder_minutes"
    }
}
```

When the legacy table is itself named `settings`, keep migrations disabled,
run Doctor, rename the legacy table to an explicit staging name, create the
canonical package schema, then run the plan and apply phases. The adoption
command refuses to read from the configured canonical target table. Manifest
size and record limits are controlled by `nvl-settings.adoption.*`.

## TypeScript

## Tenant ownership

Definitions remain immutable platform source. A definition must opt in with
`tenant_override=true` before a tenant may store a value. Adopt existing rows
through the Settings tenancy adapter; mixed platform/tenant identities use the
persisted ownership discriminator, and cache invalidation uses the captured
row identity rather than ambient worker context.

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
```

Declarations use `Nvl.Settings.*`.

## Development

```bash
composer install
composer quality
```

The suite covers discovery, duplicate detection, strict codecs, nullable
overrides, effective sources, idempotent writes, synchronization/orphans,
malformed and serialized caches, after-commit invalidation, rollback-safe
events, bounded bulk reads, stale writes, config overrides, authorization, API
errors, routes, and adoption checks.

See [UPGRADING.md](UPGRADING.md), [SECURITY.md](SECURITY.md),
[CONTRIBUTING.md](CONTRIBUTING.md), and [CHANGELOG.md](CHANGELOG.md).

## Supported PHP usage

The source `@api` declarations identify supported workflows, extension contracts, and value types. Public members marked `@internal` and untagged implementation types remain package-owned. Concrete Actions retain their existing constructors, qualifiers, and `execute()` signatures.

A package model returned or accepted by a public workflow is an identity/result handle. Use its declared type and `getKey()`, `getKeyName()`, `getMorphClass()`, `getRouteKey()`, `getRouteKeyName()`, `is()`, `isNot()`, and `relationLoaded()`. Read only explicitly declared in-memory `@nvl-consumer-read` fields; ordinary model PHPDocs and fillable attributes do not grant consumer reads. Obtain display projections through public reads. Persistence, additional model queries, relation access/loading, and generic model serialization are outside this contract. Host-model queries remain available, while traversal or aggregates of package capability relations require the package public reader or authorized adapter.

## Testing your app

Inject `GetSettingContract`, `GetManySettingsContract`, `SetSettingContract`,
`ResetSettingContract`, `ListSettingsContract`, or `ValidateSettingsSourcesContract`
for focused workflows. Reuse `SettingRepository` for repository access; the
`Setting` facade's existing container alias targets that same contract and
retains its scoped lifetime. Clear its resolved facade cache when replacing a
repository after first facade use.

```php
use Nvl\Settings\Contracts\GetSettingContract;
use Nvl\Settings\Data\SettingValueData;
use Nvl\Settings\Enums\SettingType;

final readonly class ReadFeatureFlag
{
    public function __construct(private GetSettingContract $settings) {}

    public function enabled(): bool
    {
        return $this->settings->execute('app.features.enabled')->value === true;
    }
}

$value = new SettingValueData(
    key: 'app.features.enabled', value: true, source: 'definition',
    type: SettingType::Boolean, revision: 0, definitionHash: 'fixture',
    hasOverride: false, orphaned: false,
);
$settings = Mockery::mock(GetSettingContract::class);
$settings->shouldReceive('execute')->once()->with('app.features.enabled')->andReturn($value);
$this->app->instance(GetSettingContract::class, $settings);
expect($this->app->make(ReadFeatureFlag::class)->enabled())->toBeTrue();
```

Construct definition/effective-value DTO fixtures directly. For a model result
handle use `new Nvl\Settings\Models\Setting` without writing it; use real package
workflows on the configured schema when testing persistence, cache invalidation,
and optimistic revisions. `InteractsWithSettings` remains the definition helper
for discovering application declarations, not a persistence substitute.
Mock repository calls in host orchestration tests and test the real scoped
repository separately across request/job lifecycle resets.

In Laravel application tests, register a native Mockery interface mock or a small
implementation with `$this->app->instance(Contract::class, $substitute)` before
resolving your application service. A host binding installed before package
registration is retained; later contract replacements affect subsequent
resolutions. Rebuild previously resolved host services after replacing their
dependencies. Concrete implementations remain callable with their original
constructors through major 5. Mocks exercise your application orchestration;
package authorization, persistence, and external effects need real integration
tests.

SettingRepository keeps its scoped lifetime using scopedIf. The Setting facade continues through the alias of this same contract. InteractsWithSettings retains definition-only scope.

For static consumer checks, include the shipped
[`consumer-audit.neon`](https://github.com/nvl-laravel-suite/core/blob/main/support/consumer-audit.neon) from
`vendor/nvl/core/support/consumer-audit.neon` in your host PHPStan configuration
and configure explicit `nvlConsumer.testPaths` for factory-backed tests. The
extension checks supported APIs and model/query boundaries; it does not prove
authorization or arbitrary dynamic SQL.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine the read-only checks from loaded NVL package providers. Errors fail the gate, and strict mode also fails warnings. This package's existing Doctor command remains available and uses the same package-owned inspection service.

## Next major: isolated schema identities

Use `nvl-settings.tables.<logical-key>` for every table and `nvl-settings.connection` for its database connection. Null connection inherits `nvl-core.connection`, then Laravel's default. Tables are resolved at runtime by the package table definition helper.

| Logical key | New default | Previous name |
| --- | --- | --- |
| `settings` | `nvl_settings_settings` | `settings` |

Migration filenames contain `nvl_settings_`. Existing installations must complete the upgrade in `UPGRADING.md` before running new migrations. A pending creator rejects an existing target before that owned migration runs; use `nvl:schema:preflight` for an explicit whole-batch check; legacy storage with old history needs an ownership decision.

## Canonical configuration ownership

Use `nvl-settings` settings in `config/nvl-settings.php` and canonical package environment names. Old generic roots are foreign unless an upgrading NVL host explicitly selects them in Core's default-off compatibility. Canonical false/null/empty values win; no old roots are populated or written back. Keep logical package/resource IDs unchanged. Review [Core's rename inventory and cache/worker cutover](https://github.com/nvl-laravel-suite/core/blob/main/UPGRADING.md#major-5-canonical-configuration-and-environment).

## Testing your app

Inject the supported contract rather than constructing its concrete Action or querying package tables. Replace `Nvl\Settings\Contracts\GetSettingContract` in Laravel's native container for a host-workflow test:

```php
use Nvl\Settings\Contracts\GetSettingContract;

$double = Mockery::mock(GetSettingContract::class);
$this->app->instance(GetSettingContract::class, $double);
// Configure the exact execute arguments and documented return value for your host case.
```

The package's conditional native binding preserves host substitutions. Production uses the real contract; test doubles do not prove its storage/authorization behavior.

A detached fixture for a returned identity/data handle is:

```php
use Nvl\Settings\Models\Setting;
$fixture = Setting::factory()->withoutParents()->make();
```

Ordinary `make()` may persist declared package parents. `withoutParents()->make()` disables parent expansion/admission for detached fixtures; use explicit persisted parents/owners and matching effective connections for a real `create()`. Factories do not authorize workflows, call Stripe, create backing Media objects or publish Template artifacts. Enabled tenancy requires explicit admitted persisted tenants/parents. Your host test installation supplies Faker; no test runner is a runtime package dependency.

The shipped `Nvl\Settings\Testing\InteractsWithSettings` helper is definition-only. It registers test definitions; it does not replace persistence, caches, transactions or tenant admission.

Use Laravel `Event::fake()`, `Queue::fake()`, `Mail::fake()` or `Storage::fake()` only for the effects the host test intends to isolate. Use real commits/listeners for timing proof. Add the optional Core consumer boundary rules to host PHPStan:

```neon
includes:
    - vendor/nvl/core/support/consumer-audit.neon
parameters:
    nvlConsumer:
        testPaths: [tests]
        tableNames: []
        exceptions: []
```

Rules read installed public metadata without suite boot. They flag internal symbols, package model queries/writes, capability relations and owned tables; they cannot prove dynamic code or runtime authorization. Exact exceptions require `file`, `identifier`, `symbol`, and a documented `reason`. The published 5.x family is verified through the local Dagger release gate on PHP 8.4/Laravel 13, including owning suites, MySQL/PostgreSQL persistence contracts and sealed Tenancy consumers. Fresh public Composer installation, discovery and configuration/route caching are verified. PHP 8.5, Laravel 12, MariaDB and the full independent archive matrix require separate evidence. See the [verification and release policy](https://github.com/nvl-laravel-suite/laravel-suite#verification-and-releases).

### Shipped factory states

These runtime builders keep Laravel's native Factory API. The listed methods name explicit supported parent/owner/lifecycle states; follow each factory's native admission requirements. Detached examples above do not assert persistence validity.

| Factory | Explicit states |
| --- | --- |
| [`SettingFactory`](database/factories/SettingFactory.php) | Native Factory states only |

## Error codes and events

All recognized package failures implement `Nvl\Support\Contracts\PackageException`; only `RespondableException` opts into safe response metadata. Keep native PHP programmer errors and Laravel/SDK exceptions distinct. The optional `PackageExceptionRenderer` is registered by the host in `withExceptions`; it leaves unrelated, marker-only and non-JSON handling to the host. Its JSON envelope is `{message:string, code:string, context:object}`. Request locale is host-owned; diagnostics/previous exceptions are not public copy. Event schemas and source connections are documented in [events](docs/events.md).

The table lists enum discriminators, including any successful codes retained for compatibility. A code is not itself an HTTP status; the throwing exception's `suggestedStatus()` is authoritative, especially legacy/custom constructors. Empty context renders as `{}`; only documented JSON-safe context is presented.

| Code | Suggested status | Public context | Translation key |
| --- | --- | --- | --- |
| `operation_failed` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-settings::responsecode.operation_failed` |
| `stale_setting_version` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-settings::responsecode.stale_setting_version` |
| `unknown_setting` | 404 | Declared safe scalar/array map; otherwise `{}` | `nvl-settings::responsecode.unknown_setting` |
| `duplicate_setting` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-settings::responsecode.duplicate_setting` |
| `invalid_definition` | 500 | Declared safe scalar/array map; otherwise `{}` | `nvl-settings::responsecode.invalid_definition` |


## License

Released under the [MIT License](LICENSE).
