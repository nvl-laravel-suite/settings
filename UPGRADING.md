# Upgrading NVL Settings

## Upgrading to 1.0

Version 1.0 replaces the old low-level table with UUID records, source-controlled definitions, scope, fallback, metadata, hashes, revisions, and synchronization timestamps.

1. Set `settings.migrations.enabled=false` for an existing table.
2. Run `php artisan nvl:settings:doctor --strict --format=json`.
3. Import compatible values through an application-owned bridge while preserving keys and recording checksums.
4. Declare definitions and optional scopes in `*.settings.php` or
   `*.settings.json` files under configured roots.
5. Preview `php artisan nvl:settings:sync --dry-run`.
6. Use expected revisions for mutations.
7. Enable config overrides or management routes only after schema and authorization checks.
8. Replace `settings.discovery.pattern` with
   `settings.discovery.patterns=['*.settings.php', '*.settings.json']`.
9. Replace `settings.management.prefix` with `settings.management.path` and
   set `settings.management.name` when custom route names are required.
10. Send `expectedRevision=0` for first writes and the returned positive
    revision thereafter. Management writes no longer accept a missing token.
11. Rebuild discovery with `nvl:settings:cache`; validate/sync operations now
    rescan configured roots so stale maps cannot hide removed or invalid files.
12. If adopting a pre-release v1 table, add and backfill `has_override` in the
    application-owned adoption bridge before enabling the package migration.
    A nullable override now has explicit state and is no longer inferred from
    `value IS NOT NULL`.
13. Replace `SettingRepository::get($key, $fallback)` with `get($key)`;
    definition defaults are the only fallback source.
14. Replace `set([...])` batch calls with `setMany([...])`.
15. Clear any configured settings value cache after deployment. The default
    primitive-payload cache key changed from `nvl:settings` to
    `nvl:settings:v2` for Laravel 13-safe deserialization.
16. Validate stored values before deployment. Booleans now use only `0`/`1`,
    integers require canonical encoding, dates require `Y-m-d`, and date-times
    require timezone-aware ISO 8601 input.
17. Do not schedule config-mapped settings. They are boot-time process
    snapshots and canonical mutations now reject validity windows.

Do not migrate user preferences or secrets into this package.
