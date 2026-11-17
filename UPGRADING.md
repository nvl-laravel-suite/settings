# Upgrading NVL Settings to Light

Light requires PHP 8.4, Laravel 13 and Core 7. The package no longer requires Spatie Laravel Data, TypeScript Transformer, or Tenancy at runtime. Native Ownership is optional. Refresh the package lockfile and use a no dev Composer install to verify a production host.

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

### Checkpointed adoption through nvl:adopt

Adoption runs through nvl:adopt with reviewed, checkpointed batches and an explicit activate step. Committed batches survive a failure. Keep writers stopped until verify and activate pass. SettingsAdopter::execute() stays atomic in 7.x. Run `php artisan nvl:adopt settings plan|apply|verify|activate <manifest.json>` with the same manifest `nvl:settings:adopt` takes; `apply` and `activate` require the reviewed `--fingerprint`, and a failed `apply` resumes with `--run`. Each batch writes its overrides through `SettingWriter` at the reviewed expected revision and commits with its checkpoint, so cache invalidation and `SettingChanged` fire once per committed write. A target that changed after it was staged blocks the plan instead of being overwritten. Publish and run the Core adoption migration (`nvl-core.adoption.migrations.enabled`) first. Batching happens only through `nvl:adopt`; `nvl:settings:adopt` and `SettingsAdopter::execute()` still roll back every target on any failure.

## Unreleased: stricter writes, actors and adoption resume

- Requires the Core release that adds `AdoptionAdapter::assertCheckpoint()`, which the settings adoption adapter implements to refuse resuming over deleted or changed staged overrides.
- Eloquent actors are reloaded from the write connection in `SettingWriter` and in every `nvl:adopt settings` phase. Authorization failures now carry Core's messages (`A persisted canonical actor is required.`, `The actor is unavailable.`) instead of the writer's own; match on `AuthorizationException`, not its message.
- A model observer or listener that returns `false` from `saving`, `updating`, `creating` or `deleting` on a setting now makes `set`, `reset` and `sync` throw `SettingWriteRejectedException` and roll back. Before, the call reported success. Remove such vetoes or handle the exception.
- `nvl:settings:doctor` fails `schema.index.identity` for a partial, invalid or not-ready unique identity index. Replace it with the package's complete unique index.
- A resumed `nvl:adopt settings apply` refuses when an override it already staged was deleted or changed. Find what removed or changed it and restore the staged row before resuming; the run never rewinds or rewrites it.
- Definitions remain file-defined. Keep runtime create, rename, retype and delete of arbitrary settings in the host, and adopt only overrides of definitions added to source.

## Unreleased: NUL-free text and exact JSON floats

- Text and enum writes, and definition defaults, containing a NUL byte now fail validation instead of being stored (PostgreSQL truncated them at the NUL). Run `php artisan nvl:settings:doctor`: `schema.value-codec` fails for any such row an earlier SQLite or MySQL write stored; rewrite those values without the NUL.
- JSON settings now serialize floats with their zero fraction, so `['ratio' => 1.0]` reads back as `1.0` instead of `1`. Stored rows are not rewritten and stay valid. A definition whose JSON default holds such a float gets a new definition hash, so the next `nvl:settings:sync` updates its synchronized row once.

## Native ownership and configuration

Enable and adopt native Ownership separately before using owner scopes. The retired Tenancy bridge is no longer a Settings authority source. Unresolved native context fails closed. Keep config mapped definitions central and opt into `nvl-settings.overrides.enabled`; wrap request work in `PlatformSettingsRuntime::run` where required. Job hooks restore the preceding configuration after success, nested execution or failure. Settings no longer installs management routes or middleware automatically. Recreate only your host's authorized HTTP surface.

## Validity window storage

SQLite and PostgreSQL already store these validity windows without session time-zone conversion. Retain the validity migration filename in published migration histories.

See [README](README.md) for current service signatures and [events](docs/events.md) for event fields.
