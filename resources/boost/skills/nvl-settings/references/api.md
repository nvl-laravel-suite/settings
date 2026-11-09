# Public API: nvl/settings

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\Settings\Contracts\SettingRepository`
- `Nvl\Settings\Contracts\SettingsAuditContextProvider`
- `Nvl\Settings\Contracts\SettingsAuthorization`
- `Nvl\Settings\Database\Factories\SettingFactory`
- `Nvl\Settings\Enums\SettingAbility`
- `Nvl\Settings\Enums\SettingPruneStrategy`
- `Nvl\Settings\Enums\SettingType`
- `Nvl\Settings\Enums\SettingsResponseCode`
- `Nvl\Settings\Events\SettingChanged`
- `Nvl\Settings\Exceptions\DuplicateSettingException`
- `Nvl\Settings\Exceptions\InvalidDefinitionException`
- `Nvl\Settings\Exceptions\SettingException`
- `Nvl\Settings\Exceptions\StaleSettingVersionException`
- `Nvl\Settings\Exceptions\UnknownSettingException`
- `Nvl\Settings\Facades\Setting`
- `Nvl\Settings\Models\Setting`
- `Nvl\Settings\Rules\IntegerListBetween`
- `Nvl\Settings\Rules\IntegerMapBetween`
- `Nvl\Settings\Services\PlatformSettingsRuntime`
- `Nvl\Settings\Services\SettingModelRegistry`
- `Nvl\Settings\Services\SettingReader`
- `Nvl\Settings\Services\SettingWriter`
- `Nvl\Settings\Services\SettingsAdopter`
- `Nvl\Settings\Services\SettingsDoctor`
- `Nvl\Settings\Services\SettingsSourceValidator`
- `Nvl\Settings\Support\Definition`
- `Nvl\Settings\Support\DefinitionRepository`
- `Nvl\Settings\Support\SettingAuditContext`
- `Nvl\Settings\Support\SettingSubjectReference`
- `Nvl\Settings\Support\SettingsRules`

## Nvl\Settings\Contracts\SettingRepository

```text
interface Nvl\Settings\Contracts\SettingRepository
public abstract function get(string $key): mixed
public abstract function has(string $key): bool
```

## Nvl\Settings\Contracts\SettingsAuditContextProvider

```text
interface Nvl\Settings\Contracts\SettingsAuditContextProvider
public abstract function metadata(): array
@return array{request_id?: ?string, ip_address?: ?string, user_agent?: ?string}
```

## Nvl\Settings\Contracts\SettingsAuthorization

```text
interface Nvl\Settings\Contracts\SettingsAuthorization
public abstract function authorize(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Settings\Enums\SettingAbility $ability, ?string $key=null): void
```

## Nvl\Settings\Database\Factories\SettingFactory

```text
final class Nvl\Settings\Database\Factories\SettingFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<Setting>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Settings\Models\Setting>
public function definition(): array
@return array<model-property<Setting>, mixed>
resolved @return array<model-property<Nvl\Settings\Models\Setting>, mixed>
public function modelName(): string
@return class-string<Setting>
resolved @return class-string<Nvl\Settings\Models\Setting>
```

## Nvl\Settings\Enums\SettingAbility

```text
enum Nvl\Settings\Enums\SettingAbility implements BackedEnum, UnitEnum: string
public const Nvl\Settings\Enums\SettingAbility List=Nvl\Settings\Enums\SettingAbility::List="list"
public const Nvl\Settings\Enums\SettingAbility Reset=Nvl\Settings\Enums\SettingAbility::Reset="reset"
public const Nvl\Settings\Enums\SettingAbility Set=Nvl\Settings\Enums\SettingAbility::Set="set"
public const Nvl\Settings\Enums\SettingAbility Status=Nvl\Settings\Enums\SettingAbility::Status="status"
public const Nvl\Settings\Enums\SettingAbility View=Nvl\Settings\Enums\SettingAbility::View="view"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Settings\Enums\SettingPruneStrategy

```text
enum Nvl\Settings\Enums\SettingPruneStrategy implements BackedEnum, UnitEnum: string
public const Nvl\Settings\Enums\SettingPruneStrategy Delete=Nvl\Settings\Enums\SettingPruneStrategy::Delete="delete"
public const Nvl\Settings\Enums\SettingPruneStrategy Ignore=Nvl\Settings\Enums\SettingPruneStrategy::Ignore="ignore"
public const Nvl\Settings\Enums\SettingPruneStrategy Orphan=Nvl\Settings\Enums\SettingPruneStrategy::Orphan="orphan"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Settings\Enums\SettingType

```text
enum Nvl\Settings\Enums\SettingType implements BackedEnum, UnitEnum: string
public const Nvl\Settings\Enums\SettingType Boolean=Nvl\Settings\Enums\SettingType::Boolean="bool"
public const Nvl\Settings\Enums\SettingType Date=Nvl\Settings\Enums\SettingType::Date="date"
public const Nvl\Settings\Enums\SettingType DateTime=Nvl\Settings\Enums\SettingType::DateTime="date_time"
public const Nvl\Settings\Enums\SettingType Decimal=Nvl\Settings\Enums\SettingType::Decimal="decimal"
public const Nvl\Settings\Enums\SettingType Enum=Nvl\Settings\Enums\SettingType::Enum="enum"
public const Nvl\Settings\Enums\SettingType Integer=Nvl\Settings\Enums\SettingType::Integer="int"
public const Nvl\Settings\Enums\SettingType Json=Nvl\Settings\Enums\SettingType::Json="json"
public const Nvl\Settings\Enums\SettingType Text=Nvl\Settings\Enums\SettingType::Text="string"
public function baseRules(): array
@return list<string>
public function deserialize(?string $raw): mixed
@throws InvalidArgumentException
@throws JsonException
public function serialize(mixed $value): ?string
@throws InvalidArgumentException
@throws JsonException
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Settings\Enums\SettingsResponseCode

```text
enum Nvl\Settings\Enums\SettingsResponseCode implements BackedEnum, Nvl\Support\Contracts\ResponseCode, UnitEnum: string
public const Nvl\Settings\Enums\SettingsResponseCode DuplicateSetting=Nvl\Settings\Enums\SettingsResponseCode::DuplicateSetting="duplicate_setting"
public const Nvl\Settings\Enums\SettingsResponseCode InvalidDefinition=Nvl\Settings\Enums\SettingsResponseCode::InvalidDefinition="invalid_definition"
public const Nvl\Settings\Enums\SettingsResponseCode OperationFailed=Nvl\Settings\Enums\SettingsResponseCode::OperationFailed="operation_failed"
public const Nvl\Settings\Enums\SettingsResponseCode StaleSettingVersion=Nvl\Settings\Enums\SettingsResponseCode::StaleSettingVersion="stale_setting_version"
public const Nvl\Settings\Enums\SettingsResponseCode UnknownSetting=Nvl\Settings\Enums\SettingsResponseCode::UnknownSetting="unknown_setting"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Settings\Events\SettingChanged

```text
final readonly class Nvl\Settings\Events\SettingChanged implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $id, string $key, int $revision, string $operation, ?Nvl\Settings\Support\SettingAuditContext $context=null, ?Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope $ownershipEnvelope=null, int $schemaVersion=1): mixed
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly Nvl\Settings\Support\SettingAuditContext $context
public readonly string $id
public readonly string $key
public readonly string $operation
public readonly int $revision
public readonly int $schemaVersion
public readonly Nvl\Settings\Support\SettingSubjectReference $subject
```

## Nvl\Settings\Exceptions\DuplicateSettingException

```text
final class Nvl\Settings\Exceptions\DuplicateSettingException extends Nvl\Settings\Exceptions\SettingException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Settings\Exceptions\InvalidDefinitionException

```text
final class Nvl\Settings\Exceptions\InvalidDefinitionException extends Nvl\Settings\Exceptions\SettingException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Settings\Exceptions\SettingException

```text
abstract class Nvl\Settings\Exceptions\SettingException extends Exception implements Nvl\Support\Contracts\RespondableException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Settings\Exceptions\StaleSettingVersionException

```text
final class Nvl\Settings\Exceptions\StaleSettingVersionException extends Nvl\Settings\Exceptions\SettingException implements Illuminate\Contracts\Debug\ShouldntReport
public static function forKey(string $key, ?Throwable $previous=null): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Settings\Exceptions\UnknownSettingException

```text
final class Nvl\Settings\Exceptions\UnknownSettingException extends Nvl\Settings\Exceptions\SettingException implements Illuminate\Contracts\Debug\ShouldntReport
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Settings\Facades\Setting

```text
final class Nvl\Settings\Facades\Setting extends Illuminate\Support\Facades\Facade
@method static mixed get(string $key)
@method static bool has(string $key)
```

## Nvl\Settings\Models\Setting

```text
class Nvl\Settings\Models\Setting extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function fullKey(): string
public function getConnectionName(): ?string
public function getTable(): string
public function hasActiveOverride(): bool
public function isCustomised(): bool
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Settings\Models\Setting>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function resolved(): mixed
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property Carbon $created_at
resolved @property Carbon\Carbon $created_at
@property string $definition_hash
@property mixed $fallback
@property bool $has_override
@property string $id
@property string $key
@property (array<string, mixed> | null) $metadata
@property string $namespace
@property (Carbon | null) $orphaned_at
resolved @property (Carbon\Carbon | null) $orphaned_at
@property (string | null) $owner_reference
@property (string | null) $owner_scope_key
@property int $revision
@property string $scope
@property (Carbon | null) $synced_at
resolved @property (Carbon\Carbon | null) $synced_at
@property SettingType $type
resolved @property Nvl\Settings\Enums\SettingType $type
@property Carbon $updated_at
resolved @property Carbon\Carbon $updated_at
@property (Carbon | null) $valid_from
resolved @property (Carbon\Carbon | null) $valid_from
@property (Carbon | null) $valid_until
resolved @property (Carbon\Carbon | null) $valid_until
@property mixed $value
```

## Nvl\Settings\Rules\IntegerListBetween

```text
final readonly class Nvl\Settings\Rules\IntegerListBetween implements Illuminate\Contracts\Validation\ValidationRule, Stringable
public function __construct(int $minimum, int $maximum): mixed
public function __toString(): string
public function isValid(mixed $value): bool
public function validate(string $attribute, mixed $value, Closure $fail): void
public readonly int $maximum
public readonly int $minimum
```

## Nvl\Settings\Rules\IntegerMapBetween

```text
final readonly class Nvl\Settings\Rules\IntegerMapBetween implements Illuminate\Contracts\Validation\ValidationRule, Stringable
public function __construct(int $minimum, int $maximum): mixed
public function __toString(): string
public function isValid(mixed $value): bool
public function validate(string $attribute, mixed $value, Closure $fail): void
public readonly int $maximum
public readonly int $minimum
```

## Nvl\Settings\Services\PlatformSettingsRuntime

```text
final class Nvl\Settings\Services\PlatformSettingsRuntime
public function __construct(Illuminate\Contracts\Config\Repository $configuration, Nvl\Settings\Services\PlatformSettingsReader $settings, Nvl\Settings\Services\PlatformConfigWriter $writer): mixed
public function apply(): void
@throws Throwable
public function restore(): void
public function run(Closure $operation): mixed
@template T
@param Closure(): T $operation
@return T
@throws Throwable
template Nvl\Settings\Services\PlatformSettingsRuntime::T
```

## Nvl\Settings\Services\SettingModelRegistry

```text
final class Nvl\Settings\Services\SettingModelRegistry
public function __construct(Illuminate\Contracts\Config\Repository $configuration, Nvl\Support\OwnerRegistry $identities): mixed
public function settingClass(): string
@return class-string<Setting>
resolved @return class-string<Nvl\Settings\Models\Setting>
```

## Nvl\Settings\Services\SettingReader

```text
final readonly class Nvl\Settings\Services\SettingReader
public function __construct(Nvl\Settings\Support\DefinitionRepository $definitions, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary): mixed
public function get(string $key): array
@return Resolved
resolved @return array{key: string, value: mixed, source: string, type: Nvl\Settings\Enums\SettingType, revision: int, definition_hash: string, has_override: bool, orphaned: bool, valid_from: ?string, valid_until: ?string}
public function getMany(array $keys): array
@param array<array-key, mixed> $keys
@return list<Resolved>
resolved @return list<array{key: string, value: mixed, source: string, type: Nvl\Settings\Enums\SettingType, revision: int, definition_hash: string, has_override: bool, orphaned: bool, valid_from: ?string, valid_until: ?string}>
public function paginate(array $query=[]): Illuminate\Pagination\LengthAwarePaginator
@param array{namespace?: (string | null), scope?: (string | null), search?: (string | null), page?: int, per_page?: int} $query
@return LengthAwarePaginator<int, array{definition: DefinitionRecord, value: Resolved}>
resolved @return Illuminate\Pagination\LengthAwarePaginator<int, array{definition: array{key: string, namespace: string, scope: string, type: Nvl\Settings\Enums\SettingType, default: mixed, description: string, position: int, metadata: array<string, mixed>, definition_hash: string}, value: array{key: string, value: mixed, source: string, type: Nvl\Settings\Enums\SettingType, revision: int, definition_hash: string, has_override: bool, orphaned: bool, valid_from: ?string, valid_until: ?string}}>
```

## Nvl\Settings\Services\SettingWriter

```text
final readonly class Nvl\Settings\Services\SettingWriter
public function __construct(Illuminate\Database\DatabaseManager $database, Nvl\Settings\Services\SettingValueValidator $values, Nvl\Settings\Services\SettingCache $cache, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Settings\Services\SettingCreator $creator, Nvl\Settings\Support\DefinitionRepository $definitions, Nvl\Settings\Contracts\SettingsAuthorization $authorization, Nvl\Settings\Contracts\SettingsAuditContextProvider $auditContext, Nvl\Support\Ownership\Contracts\OwnershipContext $ownershipContext, Nvl\Support\Events\DomainEventDispatcher $domainEvents): mixed
public function reset(string $key, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, int $expectedRevision): array
@return Resolved
resolved @return array{key: string, value: mixed, source: string, type: Nvl\Settings\Enums\SettingType, revision: int, definition_hash: string, has_override: bool, orphaned: bool, valid_from: ?string, valid_until: ?string}
public function set(string $key, mixed $value, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?int $expectedRevision=null, array $options=[]): array
@param array{valid_from?: (DateTimeInterface | string | null), valid_until?: (DateTimeInterface | string | null)} $options
@return Resolved
resolved @return array{key: string, value: mixed, source: string, type: Nvl\Settings\Enums\SettingType, revision: int, definition_hash: string, has_override: bool, orphaned: bool, valid_from: ?string, valid_until: ?string}
public function sync(?string $provider=null, Nvl\Settings\Enums\SettingPruneStrategy $prune=\Nvl\Settings\Enums\SettingPruneStrategy::Orphan, bool $dryRun=false): array
@return array{synchronized: int, orphans: int, failures: list<string>}
```

## Nvl\Settings\Services\SettingsAdopter

```text
final readonly class Nvl\Settings\Services\SettingsAdopter
public function __construct(Nvl\Settings\Services\SettingsAdoptionManifest $manifests, Nvl\Settings\Support\DefinitionRepository $definitions, Nvl\Settings\Services\SettingValueValidator $values, Nvl\Settings\Services\SettingWriter $writer, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary): mixed
public function execute(array $manifest, bool $apply=false, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|null $actor=null): array
@param array<array-key, mixed> $manifest
@return array{mode: string, source_table: string, reconciliation: array{expected: int, source: int, mapped: int, matched: int, created: int, updated: int, unchanged: int}}
```

## Nvl\Settings\Services\SettingsDoctor

```text
final readonly class Nvl\Settings\Services\SettingsDoctor
public function __construct(Nvl\Settings\Support\DefinitionRepository $definitions): mixed
public function inspect(): array
@return list<Check>
resolved @return list<array{key: string, severity: ('error' | 'warning' | 'info'), passed: bool, message: string}>
```

## Nvl\Settings\Services\SettingsSourceValidator

```text
final readonly class Nvl\Settings\Services\SettingsSourceValidator
public function __construct(Nvl\Settings\Support\DefinitionRepository $definitions): mixed
public function inspect(): array
@return array{valid: bool, source_count: int, definition_count: int, namespaces: list<string>, source_files: list<string>, checksum: string, error: null}
```

## Nvl\Settings\Support\Definition

```text
final readonly class Nvl\Settings\Support\Definition
@param array<int, mixed> $rules
public function __construct(string $namespace, string $scope, string $key, Nvl\Settings\Enums\SettingType $type, mixed $default, string $description, array $rules, int $position, ?string $overrides, bool $tenantOverride, array $metadata, string $source): mixed
@param array<int, mixed> $rules
@param array<string, mixed> $metadata
public function hash(): string
public readonly mixed $default
public readonly string $description
public readonly string $key
public readonly array $metadata
@param array<string, mixed> $metadata
public readonly string $namespace
public readonly ?string $overrides
public readonly int $position
public readonly array $rules
@param array<int, mixed> $rules
public readonly string $scope
public readonly string $source
public readonly bool $tenantOverride
public readonly Nvl\Settings\Enums\SettingType $type
```

## Nvl\Settings\Support\DefinitionRepository

```text
final class Nvl\Settings\Support\DefinitionRepository
public const int MAX_FULL_KEY_LENGTH=302
public const int MAX_SEGMENT_LENGTH=100
public function __construct(Nvl\Settings\Support\DefinitionFileLoader $files, Illuminate\Contracts\Foundation\Application $application): mixed
public function all(): array
@return array<string, Definition>
resolved @return array<string, Nvl\Settings\Support\Definition>
public function cachePath(): string
public function checksum(?array $map=null): string
@param (array<string, string> | null) $map
public function fake(array $definitions): void
@param array<string, array{type: SettingType, default?: mixed, description?: string, rules?: array<int, mixed>, position?: int, overrides?: (string | null), tenant_override?: bool, metadata?: array<string, mixed>}> $definitions
resolved @param array<string, array{type: Nvl\Settings\Enums\SettingType, default?: mixed, description?: string, rules?: array<int, mixed>, position?: int, overrides?: (string | null), tenant_override?: bool, metadata?: array<string, mixed>}> $definitions
public function get(string $fullKey): Nvl\Settings\Support\Definition
public function map(): array
@return array<string, string>
public function refresh(): array
@return array<string, string>
```

## Nvl\Settings\Support\SettingAuditContext

```text
final readonly class Nvl\Settings\Support\SettingAuditContext
public function __construct(?string $actorType=null, ?string $actorId=null, ?string $requestId=null, ?string $ipAddress=null, ?string $userAgent=null): mixed
public static function fromActor(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $metadata=[]): self
@param array{request_id?: ?string, ip_address?: ?string, user_agent?: ?string} $metadata
public readonly ?string $actorId
public readonly ?string $actorType
public readonly ?string $ipAddress
public readonly ?string $requestId
public readonly ?string $userAgent
```

## Nvl\Settings\Support\SettingSubjectReference

```text
final class Nvl\Settings\Support\SettingSubjectReference
public function __construct(string $id): mixed
public function toArray(): array
@return array{type: 'nvl_setting', id: string}
public readonly string $id
public readonly string $type
```

## Nvl\Settings\Support\SettingsRules

```text
final class Nvl\Settings\Support\SettingsRules
public static function integerListBetween(int $minimum, int $maximum): Nvl\Settings\Rules\IntegerListBetween
public static function integerListBetweenParameters(array $parameters): Nvl\Settings\Rules\IntegerListBetween
@param list<string> $parameters
public static function integerMapBetween(int $minimum, int $maximum): Nvl\Settings\Rules\IntegerMapBetween
public static function integerMapBetweenParameters(array $parameters): Nvl\Settings\Rules\IntegerMapBetween
@param list<string> $parameters
```
