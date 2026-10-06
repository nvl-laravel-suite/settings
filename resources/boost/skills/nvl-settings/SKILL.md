---
name: nvl-settings
description: Implement, integrate, test, or review nvl/settings in Laravel 13. Use for source-controlled setting definitions, runtime overrides, scopes, typed values, effective-value resolution, synchronization, optimistic concurrency, caching, config overrides, authorization, or schema diagnostics.
---

# NVL Settings

Use Settings for schema-driven global or runtime configuration. Do not store user preferences, secrets, UI state, or arbitrary model metadata here.

When tenancy is enabled, treat definitions as platform source and values as
owned rows. Require `tenant_override=true`, use `SettingRepository` only inside
an admitted context, and never apply tenant values to Laravel's global Config.

## Define settings

- Keep definitions in source control as `*.settings.php` or
  `*.settings.json` and discover them through `DefinitionRepository`.
- PHP definitions may use enum/rule objects. JSON definitions use portable
  `SettingType` values and string validation rules.
- Configure explicit roots, keep symlink following disabled unless required,
  and retain bounded file-count, byte, and JSON-depth limits.
- Declare scopes directly in each source file and use stable namespace, scope,
  and key identities.
- Choose a supported `SettingType` and validate defaults before synchronization.
- Use canonical values: `Y-m-d` dates, timezone-aware ISO 8601 date-times,
  booleans, canonical integers, and array-backed JSON. Preserve date-time
  microseconds, but keep scheduled validity windows at whole-second precision.
- Treat database rows as runtime overrides plus operational metadata, not the definition source.

## Read and mutate

- Use `GetSettingAction` and `GetManySettingsAction` for typed effective values.
- Use `SetSettingAction` and `ResetSettingAction` with expected revisions.
  Revision `0` is the race-safe first-write token.
- Resolve the effective value and its source explicitly.
- Treat `hasOverride` as separate from the payload; nullable overrides may
  intentionally resolve to `null`.
- Treat validity windows as part of the optimistic mutation and test scheduled,
  active, and expired states.
- Do not schedule config-mapped settings; process configuration is a boot-time
  snapshot. Restart workers after changing mapped overrides.
- Use `SettingRepository::setMany()` for atomic batches. Definition defaults
  are the sole fallback source for `get()`.
- Treat canonically equivalent repeat writes as no-ops; they must not advance
  revisions or emit mutation events.
- Consume `Nvl\Settings\Events\SettingChanged::$subject` for model-free
  activity or integration identity. It is
  `Nvl\Settings\Data\SettingSubjectReferenceData`, containing only the literal
  type `nvl_setting` and string setting ID. Map those fields to the downstream
  reference type without querying `Nvl\Settings\Models\Setting`.
- Do not add a subject constructor argument or include a setting value in the
  event. The event constructs its value-free subject from its existing ID.

For `nvl/activity`, use the exact model-free recording path:

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

## Synchronize and operate

- Run `nvl:settings:validate` before previewing
  `nvl:settings:sync --dry-run`, then run `nvl:settings:sync`.
- Treat a failing synchronization dry run as a deployment blocker; it means at
  least one persisted override is incompatible with its current definition.
- Rebuild the discovery map with `nvl:settings:cache` after source changes;
  operational validate/sync commands rescan roots and do not trust stale maps.
- Keep cache invalidation and events after the outer transaction commits. Reads on a settings connection with an active transaction bypass shared caching and see that transaction’s writes.
- Preserve live locked overrides and monotonic revisions during synchronization.
- Filter discovery with `--provider` when required.
- Use `nvl:settings:list`, `nvl:settings:reset`, `nvl:settings:cache`, and `nvl:settings:clear`.
- Run `nvl:settings:doctor --strict --format=json` before adopting an existing table.
- Keep the management API disabled by default. Configure
  `nvl-settings.management.path` and `nvl-settings.management.name`, use middleware,
  and authorize status/list/view/set/reset separately.

## Verify

Test PHP/JSON parity, malformed and oversized sources, duplicate definitions,
scope resolution, types and invalid defaults, deterministic checksums,
fallbacks, orphan policy, stale writes, cache invalidation, config overrides,
unavailable databases, configurable route path/name, authorization, and
adoption diagnostics. Include serialized cache stores, nullable overrides,
rollback-safe invalidation/events, strict type changes, one-query bulk reads,
and management API 404/409 error envelopes.

## Configurable-tenancy release discipline

- Preserve disabled compatibility and package independence; tenant support never creates an undeclared Auth or Suite dependency.
- Use registered package-owned resources, adoption adapters, Actions, and lifecycle APIs. Never add a generic tenant delete-all path or raw cross-package cleanup.
- Treat mapping/configuration hashes, interruption checkpoints, conservation evidence, worker context, tenant-leading queries, and standalone consumption as release contracts.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine checks from loaded NVL providers. Retain the package Doctor command for its detailed report; both paths reuse the package-owned inspection service.

### Brownfield storage identities

Resolve all package tables through the table helper and canonical `nvl-settings.tables.*`, connections through `nvl-settings.connection` with Core/Laravel inheritance. Defaults use `nvl_settings_*`; migration filenames include that package slug. Never silently adopt a matching table or generic migration filename. Run shared `nvl:doctor --strict --format=json` and the explicit `nvl:schema:upgrade --package=settings --claim-legacy --dry-run --format=json` before upgrading owned legacy storage. Validate the complete plan and choose one migration owner. Preserve host records, constraint names and stored morph values. Deprecated config inputs last one major; canonical options take precedence.

## Canonical configuration ownership

- Read/write `nvl-settings` configuration and publish only canonical `nvl-<package>-<resource>` tags. Keep logical package/tenant resource identifiers unchanged.
- Generic config roots and unprefixed package environment names are foreign by default. For an upgrading NVL host only, select `nvl-core.compatibility.legacy_config` package IDs and `legacy_env` explicitly; both default off. Canonical presence wins, including false/null/empty values. Legacy inputs are read without writing back and are removed in major 6.
- Use canonical `NVL_<PACKAGE>_*` variables only in config evaluation, then rebuild configuration caches and restart workers after cutover. Shared Laravel environment variables retain their names. Consult Core's versioned `support/resources/global-names.json` for all renames.
- Old global aliases and legacy route families require separate explicit `global_aliases`/`legacy_routes` package selections. Preserve collisions and use Doctor diagnostics; never grant generic permissions automatically or claim signed-link compatibility without the same authorization/signature checks.
