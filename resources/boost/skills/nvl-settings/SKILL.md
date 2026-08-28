---
name: nvl-settings
description: Implement, integrate, test, or review nvl/settings in Laravel 12–13. Use for source-controlled setting definitions, runtime overrides, scopes, typed values, effective-value resolution, synchronization, optimistic concurrency, caching, config overrides, authorization, or schema diagnostics.
---

# NVL Settings

Use Settings for schema-driven global or runtime configuration. Do not store user preferences, secrets, UI state, or arbitrary model metadata here.

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
- Keep cache invalidation and events after the outer transaction commits.
- Preserve live locked overrides and monotonic revisions during synchronization.
- Filter discovery with `--provider` when required.
- Use `nvl:settings:list`, `nvl:settings:reset`, `nvl:settings:cache`, and `nvl:settings:clear`.
- Run `nvl:settings:doctor --strict --format=json` before adopting an existing table.
- Keep the management API disabled by default. Configure
  `settings.management.path` and `settings.management.name`, use middleware,
  and authorize status/list/view/set/reset separately.

## Verify

Test PHP/JSON parity, malformed and oversized sources, duplicate definitions,
scope resolution, types and invalid defaults, deterministic checksums,
fallbacks, orphan policy, stale writes, cache invalidation, config overrides,
unavailable databases, configurable route path/name, authorization, and
adoption diagnostics. Include serialized cache stores, nullable overrides,
rollback-safe invalidation/events, strict type changes, one-query bulk reads,
and management API 404/409 error envelopes.
