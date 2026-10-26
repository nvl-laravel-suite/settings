# NVL Settings

Source controlled, typed definitions with owner scoped runtime overrides for Laravel 13. Requires PHP 8.4 and `nvl/core`; `nvl/ownership` is optional. Definitions live in configured `*.settings.php` or `*.settings.json` roots. No Settings route or middleware is registered by the package.

Install with `composer require nvl/settings` in a Laravel 13 application.

## Quickstart

Configure `nvl-settings.discovery.paths`, then register a definition such as `settings/site.settings.php`:

```php
use Nvl\Settings\Enums\SettingType;

return [
    'namespace' => 'site',
    'settings' => [
        'title' => ['type' => SettingType::Text, 'default' => 'My site'],
    ],
];
```

```php
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Services\SettingReader;

$value = app(SettingRepository::class)->get('site.title');
$record = app(SettingReader::class)->get('site.title');
```

`SettingRepository` and the `Setting` facade are read only runtime access (`get`, `has`). `SettingReader::get` returns the typed `value`, `source` (`definition` or `database`), `type`, `revision`, definition hash, override/orphan flags and validity window. A persisted row's type, value and hash remain together until successful definition reconciliation. `getMany` accepts a list of at most 500 keys and returns these records in input order with one scoped data query. `paginate` accepts only `namespace`, `scope`, `search`, `page`, and `per_page`; each item has a `definition` and `value` record. Consumers own their HTTP routes, DTOs and Actions.

## Management writes

```php
use Nvl\Settings\Services\SettingWriter;

$writer = app(SettingWriter::class);
$created = $writer->set('site.title', 'New title', $actor, expected_revision: 0);
$reset = $writer->reset('site.title', $actor, expected_revision: $created['revision']);
```

`$actor` is an explicit Laravel `Authenticatable` or `SystemActor`. Host code binds `SettingsAuthorization`, or sets `nvl-settings.management.authorization_ability` for a Gate checked with the supplied authenticated actor. The default binding denies system actors; background writes need an explicit host authorization binding. A system enum value or nullable principal never grants authority by itself.

Existing rows require their exact revision for `set` and `reset`; `null` cannot bypass compare and swap. Revision `0` is the create expectation. Equivalent writes and resets of uncustomized rows produce no event. Setting `valid_from` or `valid_until` to `null` clears that bound, while omitting a bound preserves it. Validity instants are stored as UTC seconds, including when input has an offset; an equivalent instant is a no-op. Windows are unavailable for config mapped definitions. The writer validates values, performs the scoped transaction and schedules cache invalidation and value free events after the actual outer commit. A rolled back transaction publishes neither.

`SettingWriter::sync` reconciles source definitions and existing rows without replacing live overrides. It is infrastructure reconciliation, not a management write path. Use `nvl:settings:validate`, `nvl:settings:sync --dry-run`, then `nvl:settings:sync` in deployments. `SettingsDoctor::inspect()` returns bounded diagnostic arrays (`key`, `severity`, `passed`, `message`); run `nvl:settings:doctor --strict --format=json` for schema and discovery diagnostics.

## Ownership and platform configuration

Unowned installs operate in the global scope. When native Ownership is enabled and adopted, all reader and writer value queries use admitted owner scope; unresolved ownership cannot read or write. Settings registers its native ownership resource and context participant when Ownership is installed. The retired Tenancy bridge does not supply authority.

Config overrides are opt in with `nvl-settings.overrides.enabled`. Definitions with `overrides` map a central setting to an allowlisted Laravel config key. `PlatformSettingsRuntime::run` applies the central snapshot for an explicit request or operation and restores the previous config even after failure. The provider manages the same lifecycle around jobs. Never map owner values into global config. Rebuild definition cache after source changes and restart long running workers after changing config mapped values.

## Database caching and adoption

`SettingCache` caches scoped rows under the configured versioned key and store. A transaction on the Settings connection reads its own writes without shared cache. Cache invalidation occurs after the real outer commit. `nvl:settings:cache` rebuilds discovery, while `nvl:settings:clear` clears its cache.

`nvl:settings:adopt <manifest>` plans an import from a staged legacy table; `--apply` requires a host `SettingsAuthorization` binding for `SystemActor::Background`. A version 1 manifest names `source_table`, `expected_count`, explicit `key_replacements`, and `expected_revisions` for every mapped target. Use revision `0` for absent targets and the exact current revision for existing rows. Apply uses `SettingWriter::set` for each target inside one target connection transaction, then reconciles all targets before commit. A stale revision or mismatch rolls back the whole import. The plan returns counts, never setting values. This is a staged import workflow, not automatic legacy schema conversion.

## Setting change subject reference

`SettingChanged` contains a string ID, canonical key, revision, operation, `SettingAuditContext`, and scalar `SettingSubjectReference` (`type` is `nvl_setting`). It contains no setting value or model. The explicit principal supplies audit actor identity. An optional consumer supplied `SettingsAuditContextProvider` can add bounded request metadata; the package default supplies none and never reads ambient Request or Auth. See [event schema](docs/events.md).

## Testing your app

Bind `SettingsAuthorization` in host tests, register definitions via `DefinitionRepository::fake`, and exercise `SettingReader` and `SettingWriter` with a real test database. Build consumer HTTP controllers and DTOs in the host; the package's HTTP fixture tests demonstrate 404/409 mapping without package routes. Test cache, outer transaction rollback, exact revisions, and owner admission at the service boundary. `nvl/settings` does not require Spatie Data, TypeScript Transformer, or Tenancy at runtime.

## License

MIT.
