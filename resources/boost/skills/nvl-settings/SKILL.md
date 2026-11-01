---
name: nvl-settings
description: Use when integrating typed source-defined settings, scoped runtime reads, revision-checked management writes, platform config overrides or staged settings adoption in Laravel.
---

# NVL Settings

Settings combines source-controlled definitions with typed database overrides.
Use it for application configuration; keep secrets, arbitrary metadata and UI preferences in their owning systems.
The application owns HTTP, request mapping, management policy and presentation.

## References

- [Installation](references/installation.md): discovery, configuration, migration ownership and publication tags.
- [API](references/api.md): services, definition/result shapes, policies and model contracts.
- [Usage](references/usage.md): definitions, reads/writes, validity windows, overrides and adoption.

Read the relevant usage recipe and API shape before adding a setting or call.

## Choose the capability

| Need | Capability |
| --- | --- |
| Runtime scalar lookup | `SettingRepository::get`/`has` or read-only `Setting` facade |
| Effective value plus revision/source | `SettingReader::get`/`getMany` |
| Bounded management page | `SettingReader::paginate` |
| Authorized value mutation | `SettingWriter::set`/`reset` |
| Definition reconciliation | `SettingWriter::sync` and native commands |
| Temporary central config projection | `PlatformSettingsRuntime::run` |
| Reviewed staged legacy import | `nvl:settings:adopt` |

## Definition and mutation contract

Discover trusted `*.settings.php` or `*.settings.json` files from configured bounded roots.
Filename namespace and explicit namespace must agree.
Definitions declare exact type, default, rules and permitted override behavior.
Do not forward arbitrary request keys into definition metadata or writer options.
`set` accepts only `valid_from` and `valid_until` options.
Omission preserves an existing bound; explicit null clears it.
Existing rows require their exact revision; use zero to assert first creation.
`reset` requires an existing row and its positive revision.
Null is a value only if the definition's type/rules permit it; it is not an unversioned reset.
Equivalent writes and already-reset rows are no-ops.

## Authority and model choice

Pass an explicit `Authenticatable` or deliberately authorized `SystemActor` to management writes.
Bind `SettingsAuthorization`, or configure the human Gate ability; default system access is denied.
Eloquent actors are canonically reloaded; host policies own other provider freshness contracts.
Reader/repository runtime access follows admitted ownership, not a management actor parameter.
With optional native Ownership enabled, enter the intended owner/central scope and adopt registered storage.
Unresolved owner state grants no access; an old tenant column or Tenancy bridge cannot grant authority.
Set `nvl-settings.models.setting` before provider boot for a compatible concrete `Setting` subclass.
Retain native UUID, casts, morph identity, revision hooks and ownership declaration.

## Transactions and operations

The writer owns locking/CAS and the configured model's actual connection transaction.
Cache invalidation and value-free events follow the real outer commit; rollback discards them.
`sync` reconciles definitions while retaining live overrides; it is not a management-write bypass.
Config mappings are central only, opt-in and denylisted; they cannot use validity windows.
`PlatformSettingsRuntime::run` restores preceding config even when the operation fails.
Worker hooks manage the corresponding job lifecycle; restart long-running workers after mapped-value changes.
Keep model storage/schema provisioning and transport resources in the host.

## Verify and maintain

`getMany` accepts at most 500 keys; management page size is at most 100.
Run `nvl:settings:validate`, `nvl:settings:sync --dry-run`, then reviewed synchronization.
Use `nvl:settings:doctor --strict --format=json` for bounded diagnostics.
Adoption plans are count/revision constrained; applying requires an explicit authorized background actor.
Test types, null/omission, stale revisions, selected connections, owner admission and outer rollback.
Test config restoration and actual cache isolation, not only controller validation.
Keep optional Ownership absence bootable; no Data/TypeScript runtime or package routes are required.
