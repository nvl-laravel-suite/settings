<?php

declare(strict_types=1);

namespace Nvl\Settings\Support;

use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Exceptions\InvalidDefinitionException;
use Stringable;
use Throwable;

/**
 * Immutable, validated metadata for one discoverable setting.
 *
 * @param  array<int, mixed>  $rules
 */
final readonly class Definition
{
    /**
     * Create a setting definition.
     *
     * @param  array<int, mixed>  $rules
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $namespace,
        public string $scope,
        public string $key,
        public SettingType $type,
        public mixed $default,
        public string $description,
        public array $rules,
        public int $position,
        public ?string $overrides,
        public array $metadata,
        public string $source,
    ) {}

    /**
     * Return the deterministic definition contract hash.
     */
    public function hash(): string
    {
        try {
            $payload = json_encode([
                'namespace' => $this->namespace,
                'scope' => $this->scope,
                'key' => $this->key,
                'type' => $this->type->value,
                'default' => $this->type->serialize($this->default),
                'description' => $this->description,
                'rules' => array_map(
                    self::ruleFingerprint(...),
                    $this->rules,
                ),
                'position' => $this->position,
                'overrides' => $this->overrides,
                'metadata' => $this->metadata,
            ], JSON_THROW_ON_ERROR);
        } catch (InvalidDefinitionException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            $fullKey = implode('.', array_filter([
                $this->namespace,
                $this->scope,
                $this->key,
            ]));

            throw new InvalidDefinitionException(
                "Setting [{$fullKey}] cannot be hashed deterministically.",
                previous: $throwable,
            );
        }

        return hash('sha256', $payload);
    }

    /**
     * Return a stable representation for one validation rule.
     */
    private static function ruleFingerprint(mixed $rule): mixed
    {
        if (! is_object($rule)) {
            return $rule;
        }

        $ruleClass = $rule::class;

        if ($rule instanceof Stringable) {
            return [
                'class' => $ruleClass,
                'value' => (string) $rule,
            ];
        }

        try {
            $serialized = serialize($rule);
        } catch (Throwable $throwable) {
            throw new InvalidDefinitionException(
                "Validation rule [{$ruleClass}] cannot be fingerprinted deterministically.",
                previous: $throwable,
            );
        }

        return [
            'class' => $ruleClass,
            'serializedHash' => hash('sha256', $serialized),
        ];
    }
}
