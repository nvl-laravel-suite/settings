# Changelog

All notable changes to `nvl/settings` are documented here.

## [Unreleased]

- Added a manifest-driven, dry-run-first legacy adoption API/command with
  explicit key replacements, definition/codec validation, exact count
  reconciliation, idempotent writes, and same-name schema collision checks.
- Added replaceable, value-free actor and request context to after-commit
  `SettingChanged` events.
- Added portable typed integer list/map rules for JSON setting definitions and
  fixed dotted canonical keys bypassing root value validation.
- Excluded tests and development-only analysis configuration from release
  archives, and expanded consumer operational-contract coverage.
- Consolidated the complete source-defined v1 storage schema into the clean-install create migration.
- Added deterministic, bounded `*.settings.php` and `*.settings.json`
  discovery through one validation and synchronization pipeline.
- Added source checksums, `nvl:settings:validate`, isolatable synchronization,
  and source status DTO/API output.
- Added validated, independently configurable management API path and route
  name prefix.
- Added bounded management pagination, revision-0 create semantics, scheduled
  validity windows, effective-source reporting, and concurrent create
  protection.
- Injected the Laravel application contract into definition discovery and added
  the documented `settings-migrations` publish tag.
- Hardened uncached source validation/synchronization, atomic cache refresh,
  removed-namespace orphaning, exact reset behavior, schema adoption, and
  doctor diagnostics.
- Added explicit nullable override state to the consolidated clean-install
  schema.
- Replaced permissive value casting with canonical boolean, integer, JSON,
  date, and timezone-aware date-time encoding.
- Made synchronization preserve locked live overrides and monotonic revisions
  instead of upserting a stale pre-transaction snapshot.
- Switched value caching to primitive arrays with after-commit invalidation and
  a versioned Laravel 13-safe cache key.
- Added one-query bulk reads, definition-only config mappings, stable management
  API 404/409 error envelopes, and column-based custom-table diagnostics.
- Tightened the repository to definition-owned fallbacks plus explicit
  `setMany`, and added `hasOverride` to public value contracts.
- Made canonical repeat writes idempotent, hardened primitive cache hydration,
  normalized definition hash failures, and made invalid synchronization dry
  runs fail.
- Preserved accepted date-time microseconds while requiring storage-compatible
  whole-second precision for scheduled validity windows, and rejected
  non-UTF-8 text before persistence.
- Extracted the atomic synchronization capability from its console adapter and
  tightened schema diagnostics for index uniqueness and multi-row failures.

## [1.0.0] - Unreleased

- Added source-controlled definitions, registered scopes, and typed runtime overrides.
- Added effective-value and source DTOs, revisions, synchronization, orphan handling, and events.
- Added safe optional Laravel config overrides and unavailable-database boot behavior.
- Added schema diagnostics and opt-in management routes.
