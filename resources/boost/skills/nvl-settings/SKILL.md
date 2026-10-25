---
name: nvl-settings
description: Implement or integrate nvl/settings Light source definitions, native reader and writer services, revisions, ownership, cache, platform overrides, events and staged imports in Laravel 13.
---

# NVL Settings Light

Use source controlled `*.settings.php` or `*.settings.json` definitions. Configure bounded discovery roots. Settings holds typed application configuration; keep secrets, personal preferences, UI state and arbitrary model metadata in their owning systems.

## Services

- Inject `SettingReader` for `get`, `getMany` (at most 500 keys) and `paginate` (allowlisted filters). Results include effective value, source and revision metadata. `SettingRepository` and the `Setting` facade are read only runtime `get`/`has` access.
- Inject `SettingWriter` for `set` and `reset`. Supply an actual `Authenticatable` or `SystemActor`, plus the exact expected revision for existing rows. Use `0` for a first write. `null` cannot bypass an existing row's revision.
- Bind `SettingsAuthorization` in the host or configure the Gate ability for human actors. The default checks the supplied actor through `Gate::forUser` and denies system actors. A background actor needs an explicit host policy binding.
- Pass `valid_from`/`valid_until` only when changing a window. Omission preserves a bound; explicit `null` clears it. Config mapped definitions cannot have scheduled windows.
- Let `SettingWriter::sync` reconcile definitions and preserve live overrides. It is infrastructure reconciliation, not an unversioned management writer.
- Treat canonically equivalent writes and absent resets as no ops. Events and cache invalidation occur after the actual outer source connection commit; a rollback discards both.

## Ownership and platform overrides

Native Ownership is optional. In an enabled installation, admit the intended owner or central scope explicitly; unresolved context never grants value access. The retired Tenancy bridge cannot supply authority. Keep config mapped values central and opt in to `nvl-settings.overrides.enabled`. Use `PlatformSettingsRuntime::run` around request work and rely on provider job hooks for job work; both restore the preceding config. Restart workers after changing mapped values.

## Consumers and operations

Consumers own DTOs, Actions, controllers, routes, middleware and HTTP responses. Settings does not register an HTTP surface. Package events contain scalar identity, key, revision, operation, an explicit principal derived audit context and native ownership capture. The default audit metadata provider does not inspect Request/Auth; hosts may bind a bounded request metadata provider.

Run `nvl:settings:validate`, `nvl:settings:sync --dry-run`, `nvl:settings:sync` and `nvl:settings:doctor --strict --format=json`. For staged imports, a version 1 manifest lists explicit key replacements and `expected_revisions` for every target; `--apply` requires a host binding authorizing `SystemActor::Background`. Import writes and reconciliation share one target transaction and roll back together.

Test native service behavior with real schema, exact revisions, cache, outer rollback, ownership admission, config restoration, and consumer HTTP mapping. See the package [README](../../../../README.md), [upgrade guide](../../../../UPGRADING.md), and [event schema](../../../../docs/events.md).
