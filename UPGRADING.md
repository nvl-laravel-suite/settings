# Upgrading NVL Settings

## Upgrading to 1.0

Version 1.0 replaces the old low-level table with UUID records, source-controlled definitions, scope, fallback, metadata, hashes, revisions, and synchronization timestamps.

1. Set `settings.migrations.enabled=false` for an existing table.
2. Run `php artisan nvl:settings:doctor --strict --format=json`.
3. If the legacy table is named `settings`, rename it to an explicit staging
   name before creating the canonical package table. Doctor's
   `schema.compatibility` check reports this collision directly.
4. Create a version-1 adoption manifest with the staging connection/table,
   source key/value columns, exact expected row count, and one explicit
   `key_replacements` entry per source row.
5. Run `php artisan nvl:settings:adopt manifest.json --format=json`, then apply
   the validated import with `--apply`. Unknown keys, count differences,
   invalid target definitions/codecs, duplicate targets, and missing source
   rows fail without partial writes.
6. Declare definitions and optional scopes in `*.settings.php` or
   `*.settings.json` files under configured roots.
7. Preview `php artisan nvl:settings:sync --dry-run`.
8. Use expected revisions for mutations.
9. Enable config overrides or management routes only after schema and authorization checks.
10. Replace `settings.discovery.pattern` with
   `settings.discovery.patterns=['*.settings.php', '*.settings.json']`.
11. Replace `settings.management.prefix` with `settings.management.path` and
   set `settings.management.name` when custom route names are required.
12. Send `expectedRevision=0` for first writes and the returned positive
    revision thereafter. Management writes no longer accept a missing token.
13. Rebuild discovery with `nvl:settings:cache`; validate/sync operations now
    rescan configured roots so stale maps cannot hide removed or invalid files.
14. If adopting a pre-release v1 table, add and backfill `has_override` in the
    application-owned adoption bridge before enabling the package migration.
    A nullable override now has explicit state and is no longer inferred from
    `value IS NOT NULL`.
15. Replace `SettingRepository::get($key, $fallback)` with `get($key)`;
    definition defaults are the only fallback source.
16. Replace `set([...])` batch calls with `setMany([...])`.
17. Clear any configured settings value cache after deployment. The default
    primitive-payload cache key changed from `nvl:settings` to
    `nvl:settings:v2` for Laravel 13-safe deserialization.
18. Validate stored values before deployment. Booleans now use only `0`/`1`,
    integers require canonical encoding, dates require `Y-m-d`, and date-times
    require timezone-aware ISO 8601 input.
19. Do not schedule config-mapped settings. They are boot-time process
    snapshots and canonical mutations now reject validity windows.
20. Replace consumer-only JSON collection rules with
    `settings_integer_list_between:min,max` or
    `settings_integer_map_between:min,max` where applicable. Definition rules
    validate the root value, not an undocumented `value.*` path.
21. Bind `SettingsAuditContextProvider` when the default Laravel request actor
    and correlation metadata do not match the application's audit model.

Do not migrate user preferences or secrets into this package.
