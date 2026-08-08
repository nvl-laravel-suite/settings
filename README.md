# NVL Settings — API and usage

[← NVL Laravel Suite](../../../README.md)

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/laravel-suite:^1.0` |
| Module identifier | `nvl/settings` |
| PHP namespace | `Nvl\Settings` |
| Service provider | `Nvl\Settings\Providers\SettingsServiceProvider` |
| Configuration | `config/settings.php` |

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

- PHP 8.3 or 8.4
- Laravel 12 or 13
- `nvl/data` for public DTO and generated TypeScript contracts

## Installation

```bash
composer require nvl/laravel-suite:^1.0
php artisan migrate
php artisan vendor:publish --tag=settings-config
php artisan vendor:publish --tag=settings-migrations
```

Package discovery registers `SettingsServiceProvider`. Migrations load
automatically unless `settings.migrations.enabled` is false.

```bash
php artisan vendor:publish --tag=settings-skills
```

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

The public action boundary returns `nvl/data` DTOs:

```php
use Nvl\Settings\Actions\GetSettingAction;
use Nvl\Settings\Actions\SetSettingAction;
use Nvl\Settings\Data\SettingMutationData;

$current = app(GetSettingAction::class)->execute('interface.theme');

$updated = app(SetSettingAction::class)->execute(
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

Available Actions are:

- `GetSettingAction`
- `GetManySettingsAction`
- `ListSettingsAction`
- `SetSettingAction`
- `ResetSettingAction`
- `ValidateSettingsSourcesAction`

`SettingDefinitionData`, `SettingMutationData`, and `SettingValueData` describe
definitions, writes, and effective values. Effective values include their
source (`definition` or `database`), type, revision, definition hash, and
`hasOverride` state, and orphan state. `hasOverride` is independent from the
payload, so an explicitly stored nullable override remains distinguishable from
reset state.

Set and reset acquire a row lock and reject stale revisions, including
concurrent first writes. `SettingChanged`
contains only identifiers and mutation metadata, never the setting value, and
dispatches after commit. Canonically equivalent repeat writes are no-ops: they
do not advance the revision, refresh synchronization timestamps, flush the
value cache, or emit `SettingChanged`.

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
    'path' => 'api/v1/settings',
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
transaction commits. Cache failures and database outages are not converted
into defaults.

For an existing table, disable automatic migrations during assessment and run:

```bash
php artisan nvl:settings:doctor --strict --format=json
```

The doctor checks the configured connection/table, required v1 columns,
identifier type, indexes, duplicate identities, uncached definition discovery,
cache freshness, canonical stored value encodings, and management route
security without mutating state. Package
migrations create the complete clean-install schema and do not mutate an
unrelated existing table. Duplicate cleanup, identifier conversion, and data
mapping belong in an application-owned adoption bridge.

## TypeScript

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

## License

Released under the [MIT License](LICENSE).
