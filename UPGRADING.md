# Upgrading NVL Settings

## Consumer contracts, committed events and runtime policy (5.x)

Prefer focused public interfaces in constructor injection; native implementations remain container defaults and host prebindings win. Returned models are documented identity/data handles: use package contracts for reads/writes and capability-specific batch readers instead of direct package queries. Enable the shipped Core PHPStan include in your host; do not invoke the suite workbench static audit command in a consumer.

Events now carry immutable schemaVersion=1 and scalar/DTO snapshots. Replace model-bearing event fields with the IDs listed in [events](docs/events.md); load only through an authorized public reader when needed. Only six declared legacy `*Event` names are retained as PHP aliases for major 5, removal no earlier than major 6. Migrate exact imports/listeners/fakes to canonical names, replace suffix wildcard patterns explicitly, drain old queued payloads, rebuild event caches and restart workers. Framework Verified/PasswordReset remain native classes. Source-connection callbacks are process-local after-commit publication, not a durable outbox or exactly-once delivery.

Package failures have a marker and optional response metadata. Opt into Core's JSON renderer deliberately; preserve existing host handlers and request-locale selection. Missing required host adapters produce `binding_required`/500; genuine configured authorization denial retains native handling. See the README error table and required-bindings section where applicable.

Factories ship in runtime package mappings for host tests. Ordinary make may persist parents; withoutParents()->make creates detached fixtures. Supply persisted native owners/parents and active tenants explicitly, retain source revisions, and never treat a factory row as a real storage/provider/workflow effect. Core's optional installer publishes common config without enabling features; strict Doctor and explicit deployment cache/worker steps belong in the host release process. C3/C4/E executable acceptance is pending until recorded by integration.


## Tenant adoption

Install the tenancy expansion migration, prepare a reviewed assignment map,
and run the foundation `prepare/backfill/verify/activate` lifecycle. Do not
disable tenancy after duplicate keys exist in separate ownership partitions.
Config-mapped definitions cannot permit tenant overrides.

## Upgrading to 1.0

Version 1.0 replaces the old low-level table with UUID records, source-controlled definitions, scope, fallback, metadata, hashes, revisions, and synchronization timestamps.

1. Set `nvl-settings.migrations.enabled=false` for an existing table.
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
10. Replace `nvl-settings.discovery.pattern` with
   `nvl-settings.discovery.patterns=['*.settings.php', '*.settings.json']`.
11. Replace `nvl-settings.management.prefix` with `nvl-settings.management.path` and
   set `nvl-settings.management.name` when custom route names are required.
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

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published).

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=settings --claim-legacy --migration-owner=vendor --dry-run --format=json
php artisan nvl:schema:upgrade --package=settings --claim-legacy --migration-owner=vendor --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Declare each published path and canonical identity explicitly in `nvl-core.migrations.published`; retimestamped history also needs an exact `legacy` mapping. Use `--migration-owner=vendor` after manually archiving declared copies outside loaded paths, or `--migration-owner=published` after manually replacing executable copies with current migration code and disabling vendor loading. The plan verifies ownership and preserves batches; checksums do not automatically claim files. Modified host copies remain host-owned. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, run `nvl:schema:preflight` with the same selected paths and connection, then migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.

## Tagged consumer PHP boundary

Use source `@api` workflows, extension contracts, and value types for application integration. Direct use of untagged implementations or `@internal` members is unsupported. This classification keeps existing concrete Action signatures and runtime behavior; it does not authorize package model persistence, ad hoc queries, relation traversal, or generic model serialization. Returned models are identity/result handles with only the explicitly declared in-memory read fields described in the README.

The implementation Actions `AdoptSettingsAction` are explicitly internal. Run `nvl:settings:adopt` for reviewed legacy adoption; use `GetSettingAction`, `SetSettingAction`, and `ResetSettingAction` for ordinary application settings.

`SettingValueData::fromModel` is an internal storage projector. Obtain effective value DTOs through `GetSettingAction`, `GetManySettingsAction`, or the supported Settings repository; these resolve overrides and revisions within the package.

## Application workflow contracts

SettingRepository keeps its scoped lifetime using scopedIf. The Setting facade continues through the alias of this same contract. InteractsWithSettings retains definition-only scope.

The supported workflow injection names are `GetManySettingsContract`, `GetSettingContract`, `ListSettingsContract`, `ResetSettingContract`, `SetSettingContract`, `ValidateSettingsSourcesContract`.

Inject these contracts when application workflows need substitution. Native
concrete constructors and operation signatures remain available through major 5;
internal workflow chains are unchanged. Register host implementations before
package discovery or replace the contract before resolving a new host service.
See [Testing your app](README.md#testing-your-app) for native fixtures and the
shipped consumer-audit PHPStan configuration.
