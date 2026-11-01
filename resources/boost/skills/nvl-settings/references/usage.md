# Settings consumer recipes

See [installation](installation.md) for publication/discovery/schema ownership and [API](api.md) for complete native signatures and records.

## Define source-controlled values

Place this in the host's configured discovery root as `site.settings.php`:

```php
use Nvl\Settings\Enums\SettingType;

return [
    'namespace' => 'site',
    'settings' => [
        'title' => [
            'type' => SettingType::Text,
            'default' => 'My site',
            'rules' => ['string', 'max:120'],
            'description' => 'Public site title',
            'tenant_override' => true,
        ],
    ],
];
```

`tenant_override` is the actual native definition key controlling owner override admission; it does not enable a legacy tenancy runtime. The host decides whether the value may vary by owner. Top-level keys are `namespace`, `settings`, `scopes`; scoped groups create `namespace.scope.key`, while the example creates `site.title`. Namespace must match the filename. JSON definitions use backed type strings and portable rules rather than PHP enum/rule objects.

Per-setting fields are `type`, `default`, `description`, `rules`, `position`, `overrides`, `tenant_override`, `metadata`; unknown keys reject. Types are `SettingType::Text` (`string`), `Integer` (`int`), `Decimal`, `Boolean` (`bool`), `Json`, `Enum`, `Date`, `DateTime`. Exact runtime codecs/rules decide accepted values; decimal values are canonical strings after decoding. Null defaults/overrides need compatible validation rules. `SettingsRules::integerListBetween` and `integerMapBetween` support bounded structured integer settings.

Discovery defaults to `settings`, recursively includes PHP/JSON definitions, does not follow symlinks, and bounds file count (1,000), bytes (262,144/file) and JSON depth (64). Configure roots deliberately. `DefinitionRepository::fake` is a host-test seam, not a production definition source.

## Read effective values and write with revisions

Assume `$actor` is the host's explicit authenticated principal and management authorization has been configured. Eloquent actors are reloaded from canonical storage. For other `Authenticatable` providers the host must ensure fresh authority. `SystemActor::Background` requires a host `SettingsAuthorization` binding that explicitly permits the requested ability/key; the enum itself grants nothing.

```php
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Services\SettingReader;
use Nvl\Settings\Services\SettingWriter;

$value = app(SettingRepository::class)->get('site.title');
$reader = app(SettingReader::class);
$writer = app(SettingWriter::class);
$current = $reader->get('site.title');
$updated = $writer->set('site.title', 'Updated site', $actor,
    expectedRevision: $current['revision']);
$reset = $writer->reset('site.title', $actor,
    expectedRevision: $updated['revision']);
```

`get` returns `key`, `value`, `source`, `type`, `revision`, `definition_hash`, `has_override`, `orphaned`, `valid_from`, `valid_until`. `type` is a `SettingType` enum. A definition-only read reports revision zero and definition default; source becomes `database` only while an active override supplies the value. Persisted rows retain their persisted codec type/hash until successful definition synchronization. `getMany` takes a list of at most 500 string keys, deduplicates them and returns resolved records in first-input order.

`paginate` accepts only `namespace`, `scope`, `search`, `page`, `per_page`; the first three are nullable strings, page starts at one, and per-page is 1–100 (default 50). Each paginator item contains `definition` and `value` records. Runtime reads have no management actor parameter: the application authorizes management endpoints and enters native owner scope as required.

`set` accepts `?int $expectedRevision`, default null. Null permits first creation but cannot update an existing row; prefer zero to assert absence explicitly. Existing rows require exact positive revisions, and null cannot bypass CAS. `reset` accepts only a positive revision and requires an existing row; resetting an already-uncustomized row is a no-op. Unknown keys and bad values fail inside the service. Handle `UnknownSettingException`, `StaleSettingVersionException`, validation exceptions and authorization exceptions at the host transport boundary.

## Validity windows and transaction boundaries

```php
$scheduled = $writer->set('site.title', 'Seasonal site', $actor,
    expectedRevision: $reader->get('site.title')['revision'],
    options: [
        'valid_from' => '2026-12-01T00:00:00Z',
        'valid_until' => '2027-01-01T00:00:00Z',
    ]);
$clearedEnd = $writer->set('site.title', 'Seasonal site', $actor,
    expectedRevision: $scheduled['revision'],
    options: ['valid_until' => null]);
```

Options accept only `valid_from`/`valid_until` as `DateTimeInterface|string|null`. Omitted bounds persist; null clears a bound; invalid/inverted windows reject. Equivalent instants normalize to UTC seconds and do not create unnecessary revisions. Config-mapped definitions cannot carry scheduled windows.

The service owns a transaction on the model's actual connection, locks the canonical row and applies compare-and-swap. An outer host transaction remains authoritative: cache invalidation and `SettingChanged` dispatch follow its commit, and rollback cancels them. Events contain identity/key/revision/operation and explicit-principal audit context, not setting values or ambient Request/Auth data. Bind `SettingsAuditContextProvider` only when the host needs bounded additional metadata. Canonically equivalent writes emit no duplicate event; no durable external-provider exactly-once guarantee is implied.

Cache identity includes the configured model/source/table, owner and definition hashes. A transaction on the Settings source reads its own writes without borrowing shared cache state. Keep these dimensions when substituting host cache infrastructure; a global cache key alone cannot isolate owners or alternate model connections.

## Model substitution, ownership and platform overrides

Set `nvl-settings.models.setting` to a concrete subclass of `Nvl\Settings\Models\Setting` before providers boot. The registry freezes that choice and preserves UUID identity, casts, native morph, ownership declaration and revision hooks. Provision any compatible alternate table/connection and extra columns in the host; service mutation allowlists do not expand automatically.

Without native Ownership, settings operate in global unowned scope. With it enabled, adopt/register `settings.values` and enter an admitted owner/central scope; unresolved context denies value access. The package does not translate an old tenant column into authority. Owner-varying definitions need `tenant_override: true`; central config mappings must not vary by owner.

Config projection is opt-in through `nvl-settings.overrides.enabled`. A definition's `overrides` names a trusted, permitted Laravel config key. Denied targets include app secrets/environment/timezone, database/cache and the package's own config. Use the complete lifecycle boundary:

```php
use Nvl\Settings\Services\PlatformSettingsRuntime;

$result = app(PlatformSettingsRuntime::class)->run(
    static fn (): string => (string) config('app.name'),
);
```

The application chooses the permitted mapped key and wraps the request/operation. Prior config is restored on success/failure and nesting; job hooks perform the corresponding worker lifecycle. Rebuild discovery cache after source changes and restart workers after mapped-value changes.

## Operations and staged adoption

```sh
php artisan nvl:settings:validate
php artisan nvl:settings:sync --dry-run
php artisan nvl:settings:doctor --strict --format=json
```

After reviewing the plan, `nvl:settings:sync` reconciles definitions without overwriting live overrides. `sync.prune` chooses `orphan`, `delete` or `ignore`; do not silently change pruning policy. `nvl:settings:cache` rebuilds definition discovery; `nvl:settings:clear` clears its cache. `nvl:settings:list` reports configured definitions.

`nvl:settings:adopt <manifest>` plans a reviewed staged legacy import; `--apply` requires host policy permission for `SystemActor::Background`. Version-one manifest keys are `version`, optional `source_connection`, `source_table`, optional `key_column`/`value_column` (default `key`/`value`), `expected_count`, explicit `key_replacements` and `expected_revisions`. Counts must match replacements; every target requires zero for absence or its current positive revision. Source reads stop after the declared count plus one sentinel. The default maximum is 10,000 records and 1,048,576 manifest bytes.

Apply writes and reconciliation share one target transaction, so a stale revision/count/definition mismatch rolls back the import. This imports an already-staged source; it does not invent key mappings or convert schemas. Doctor returns bounded `key`, `severity`, `passed`, `message` diagnostics. Test definitions, exact revisions, null/window behavior, configured models, real owner admission, actual cache/outer-rollback semantics and config restoration through focused host tests.
