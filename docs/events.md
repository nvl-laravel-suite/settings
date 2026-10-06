# NVL settings events

This document describes the implemented source behavior. Executable acceptance proof is pending the final testing phase. The authoritative machine-readable schema is [event-catalog.json](../resources/event-catalog.json), catalog version `1`. Event `schemaVersion` is independent of catalog version.

## Publication and listener timing

The native host dispatcher receives the captured event after the supplied source connection outer commit, or immediately when that connection has no active transaction.

Callbacks attach to the matching native connection and current nesting record; native outer/savepoint rollback discards the corresponding callbacks.

Missing source transaction records fail before commit; Mail Notifications reports and drops unusable observations.

Host after-commit listeners and queue after_commit policies can add their own deferral after publication. Host transaction infrastructure and dispatcher bindings are preserved.

Local callbacks are not an outbox. Process exit between commit and callback can lose delivery; no crash durability or exactly-once delivery is promised.

One canonical object is dispatched per qualifying producer call. This is local publication, not cross-process deduplication or a guarantee that repeated observations are unique.

Use Nvl\Support\Events\DomainEventDispatcher::dispatch($event, $writerConnection). Native Event::dispatch() is immediate and has no package interception.

## Payload security and no-op behavior

Value-free setting ID/key/revision and persisted tenant/ownership identity; context can carry actor ID, request ID, IP address and user agent. Treat request metadata as private. subject is the scalar nvl_setting reference. Private captured TenantJobEnvelope is available through tenantJobEnvelope(); it contains no model.

Unchanged writes and absent resets emit nothing. Set/reset facts capture ownership and request context before commit; never resolve tenant context later.

The context constructor parameter is nullable; the public context property is a non-null SettingAuditContextData defaulted during construction. subject is computed as SettingSubjectReferenceData(id), whose type is always nvl_setting. The private envelope default null supports construction compatibility; tenantJobEnvelope() requires a captured envelope.

Actor/owner identifiers do not grant access. Listeners must preserve the captured ownership and apply their own authorization when reading storage. Readonly payload fields and native value objects are schema facts; public constructors with mixed arrays do not create a new recursive sanitization boundary. Package producer shapes are documented below; hosts must not attach models, mutable service objects or private arbitrary data.

## Canonical events

| Event | Schema version | Trigger |
| --- | --- | --- |
| [SettingChanged](#settingchanged) | 1 | Value-free runtime setting mutation. |

### SettingChanged

`Nvl\Settings\Events\SettingChanged` · [source](../src/Events/SettingChanged.php) · event schema `1`.

Value-free runtime setting mutation.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$id` | `string` | public | `required` | — |
| `$key` | `string` | public | `required` | — |
| `$revision` | `int` | public | `required` | — |
| `$operation` | `string` | public | `required` | — |
| `$context` | `?Nvl\Settings\Data\SettingAuditContextData` | parameter | `null` | — |
| `$tenantId` | `?string` | public | `null` | — |
| `$ownershipKey` | `string` | public | `'platform'` | — |
| `$envelope` | `?Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope` | private | `null` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$id` | `string` | — |
| `$key` | `string` | — |
| `$revision` | `int` | — |
| `$operation` | `string` | — |
| `$context` | `Nvl\Settings\Data\SettingAuditContextData` | — |
| `$tenantId` | `?string` | — |
| `$ownershipKey` | `string` | — |
| `$schemaVersion` | `int` | — |
| `$subject` | `Nvl\Settings\Data\SettingSubjectReferenceData` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/ResetSettingAction.php](../src/Actions/ResetSettingAction.php) | `$connection = DB::connection($model->getConnectionName())` |
| [Actions/SetSettingAction.php](../src/Actions/SetSettingAction.php) | `$connection = DB::connection($model->getConnectionName())` |
| [SettingManager.php](../src/SettingManager.php) | `$connection = DB::connection((new Setting)->getConnectionName())` |
| [SettingManager.php](../src/SettingManager.php) | `$connection = DB::connection((new Setting)->getConnectionName())` |

## Referenced payload types

Native event field types are listed above; nested declared fields and backed enum values follow. Private captured envelopes are included because serialized/queued objects retain them. Dates use `Carbon\CarbonImmutable`. Spatie Data serialization can also carry its protected transformation metadata; recursive graph acceptance checks remain pending.

### SettingAuditContextData

`Nvl\Settings\Data\SettingAuditContextData` · [source](../src/Data/SettingAuditContextData.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$actorType` | `?string` | — |
| `$actorId` | `?string` | — |
| `$requestId` | `?string` | — |
| `$ipAddress` | `?string` | — |
| `$userAgent` | `?string` | — |

### SettingSubjectReferenceData

`Nvl\Settings\Data\SettingSubjectReferenceData` · [source](../src/Data/SettingSubjectReferenceData.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$id` | `string` | — |
| `$type` | `string` | — |

`$type` is initialized to `nvl_setting`.

### TenantContextMode

`Nvl\Support\Tenancy\Enums\TenantContextMode` · [source](../../core/support/src/Tenancy/Enums/TenantContextMode.php).

Backed string values: `Disabled = disabled`, `Unresolved = unresolved`, `Tenant = tenant`, `Platform = platform`.

### TenantContextSnapshot

`Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot` · [source](../../core/support/src/Tenancy/ValueObjects/TenantContextSnapshot.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$mode` | `Nvl\Support\Tenancy\Enums\TenantContextMode` | — |
| `$tenantId` | `?Nvl\Support\Tenancy\ValueObjects\TenantId` | — |

### TenantId

`Nvl\Support\Tenancy\ValueObjects\TenantId` · [source](../../core/support/src/Tenancy/ValueObjects/TenantId.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$value` | `string` | — |

### TenantJobEnvelope

`Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope` · [source](../../core/support/src/Tenancy/ValueObjects/TenantJobEnvelope.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$context` | `Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot` | — |
| `$version` | `int` | — |

## Deferred acceptance checks

Final testing must compare catalog types/defaults/aliases with actual classes, recursively inspect producer payloads, and prove source outer commit, nested rollback, unrelated connection independence and retry behavior without an uncommitted test-harness transaction. Where applicable it must cover legacy exact/cached/queued listeners, canonical fakes and wildcard delivery, tenant capture, package no-op guards and observational failure containment. This document does not report those checks as passing.
