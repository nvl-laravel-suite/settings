# Upgrading NVL Settings to Light

Light requires PHP 8.4, Laravel 13 and Core 6. The package no longer requires Spatie Laravel Data, TypeScript Transformer, or Tenancy at runtime. Native Ownership is optional. Refresh the package lockfile and use a no dev Composer install to verify a production host.

## Replace management integrations

| Retired package API | Light API |
| --- | --- |
| `GetSettingAction`, `GetManySettingsAction`, `ListSettingsAction` and their contracts | `SettingReader::get`, `getMany`, `paginate` |
| `SetSettingAction`, `ResetSettingAction`, manager `set`/`setMany`/`reset` | `SettingWriter::set`, `reset` with an explicit actor and expected revision |
| `SettingSynchronizer`, `SettingManager::sync` | `SettingWriter::sync` for definition reconciliation only |
| `ValidateSettingsSourcesAction` | `SettingsSourceValidator::inspect` |
| `AdoptSettingsAction` | `SettingsAdopter::execute` or the adoption command with a reviewed manifest |
| Package `Data` classes, management controller, route and middleware | Consumer owned DTOs, Actions, HTTP transport and response mapping |
| Package `Testing\InteractsWithSettings` | Consumer fixture or `DefinitionRepository::fake` |

`SettingRepository` remains a read only runtime contract (`get`, `has`). Replace old mutation calls directly; there are no forwarding aliases. For existing overrides, pass the exact current revision on every write. A missing expected revision is stale. Revision `0` is the create expectation. The writer accepts explicit `Authenticatable|SystemActor` and invokes `SettingsAuthorization` with that same principal. Set `nvl-settings.management.authorization_ability` for Gate backed human writes or bind a host policy; background system writes require a host binding.

Move request metadata collection into a consumer `SettingsAuditContextProvider`. The default is empty. `SettingChanged` now uses native `SettingAuditContext`, `SettingSubjectReference` and `OwnershipJobEnvelope`, with no Tenancy envelope, tenant ID, or Data object. Update queued listeners and event caches after draining old serialized payloads.

## Definition sync and staged imports

`nvl:settings:validate`, `nvl:settings:sync --dry-run`, and `nvl:settings:sync` keep their deployment purpose. Sync reconciles definitions and preserves valid live overrides. It cannot act as an unversioned override writer. Do not use an old `setMany` or manager mutation method for imports.

Version 1 adoption manifests require an `expected_revisions` object keyed by every mapped canonical target. Use `0` for an absent row and the exact revision for an existing row. `--apply` passes `SystemActor::Background`; bind `SettingsAuthorization` to admit that operation explicitly. The import validates source values, writes through `SettingWriter` in one target transaction and reconciles before commit. Any failure rolls back all targets. The plan is read only and returns counts without values.

## Native ownership and configuration

Enable and adopt native Ownership separately before using owner scopes. The retired Tenancy bridge is no longer a Settings authority source. Unresolved native context fails closed. Keep config mapped definitions central and opt into `nvl-settings.overrides.enabled`; wrap request work in `PlatformSettingsRuntime::run` where required. Job hooks restore the preceding configuration after success, nested execution or failure. Settings no longer installs management routes or middleware automatically. Recreate only your host's authorized HTTP surface.

## Validity window storage

Migration `2026_11_11_000000_nvl_settings_store_setting_validity_as_datetime` converts `valid_from` and `valid_until` to `DATETIME` on MySQL and MariaDB and changes nothing on other drivers. Vendor-loaded migrations pick it up with `php artisan migrate`; hosts that own published migrations must publish and run it. Its rollback refuses to narrow the columns while a stored window lies outside 1970-2038.

See [README](README.md) for current service signatures and [events](docs/events.md) for event fields.
