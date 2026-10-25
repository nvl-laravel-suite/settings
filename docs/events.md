# NVL Settings events

The [event catalog](../resources/event-catalog.json) records the native schema. The Settings writer publishes through Core's `DomainEventDispatcher` on the actual Settings connection after its outer commit. Nested or outer rollback discards the callback. This is local publication, not a durable outbox.

### SettingChanged

`Nvl\Settings\Events\SettingChanged` has schema version 1. A qualifying set or reset publishes a value free fact with public `id`, `key`, `revision`, `operation`, `context`, `subject`, and `schemaVersion`. `context` is a native `SettingAuditContext` with actor type and ID derived from the supplied mutation principal. A consumer may provide bounded request ID, IP address and user agent metadata; the package default supplies no request metadata. `subject` is a native `SettingSubjectReference` containing type `nvl_setting` and the setting ID.

The event privately captures a native `OwnershipJobEnvelope` before publication. It contains scalar admission facts, no model, setting value, or ambient principal. A listener must enter the captured scope and enforce its own access policy before reading storage. `ownershipJobEnvelope()` exposes that capture for native queue restoration.

Equivalent set writes and resets of uncustomized rows emit no event. A rolled back transaction emits no event and leaves the shared cache unchanged. Producers are `SettingWriter::set` and `SettingWriter::reset`; definition synchronization emits no management mutation event. Old Tenancy envelope and Data payload classes are retired, so hosts must drain old queued payloads and update listeners before deployment.
