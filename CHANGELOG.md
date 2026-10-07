# Changelog


All notable changes to `nvl/settings` are documented here.

## [5.0.0] — release candidate (unpublished)

### Changed

- SettingRepository keeps its scoped lifetime using scopedIf. The Setting facade continues through the alias of this same contract. InteractsWithSettings retains definition-only scope. Document contract substitution and truthful host fixtures in Testing your app.
- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Prepare lockstep major 5 with required and development NVL peer floors of `^5.0`. This candidate has not been tagged or published.
- Default database config overrides to off and apply explicitly enabled projection only during request/job execution.
- Restore projected configuration at lifecycle boundaries; discovery and config caching perform no settings queries.
- Review [UPGRADING.md](UPGRADING.md) before adopting the new names and infrastructure boundaries.

## [2.2.1] - 2026-09-26

### Documentation

- Clarify public support, contribution, and private security reporting paths.

## [2.2.0] - 2026-09-25

### Changed

- Prepare `nvl/settings` for independent Composer and Git publication; require `nvl/core` for shared Support and Data services.

## [2.0.1] - 2026-09-22

### Added

- Added mixed platform/tenant setting ownership, immutable opt-in definitions,
  captured cache identities, package adoption, and tenant-aware diagnostics.

### Fixed

- Bypass shared caching during settings-connection transactions so reads see pending writes without caching values that can still roll back.
- Exercise cache serialization and invalidation with real commits in a dedicated test case.

## [2.0.0] - 2026-08-29

### Changed

- Established `SettingRepository`, typed Actions, value-free event subjects,
  and the Setting facade as the 2.0 consumer boundary; direct consumer
  Setting-model queries now fail Suite audit.

## [1.0.7] - 2026-08-22

### Changed

- Aligned the documented runtime requirement with the PHP 8.4+ package
  baseline.

## [1.0.5] - 2026-08-12

### Changed

- Released unchanged under the suite's shared version.

## [1.0.2] - 2026-08-12

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

## [1.0.0] - 2026-08-08

- Added source-controlled definitions, registered scopes, and typed runtime overrides.
- Added effective-value and source DTOs, revisions, synchronization, orphan handling, and events.
- Added safe optional Laravel config overrides and unavailable-database boot behavior.
- Added schema diagnostics and opt-in management routes.
