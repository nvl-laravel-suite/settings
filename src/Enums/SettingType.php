<?php

declare(strict_types=1);

namespace Nvl\Settings\Enums;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Defines the supported setting types and their canonical storage codec.
 */
enum SettingType: string
{
    case Text = 'string';
    case Integer = 'int';
    case Decimal = 'decimal';
    case Boolean = 'bool';
    case Json = 'json';
    case Enum = 'enum';
    case Date = 'date';
    case DateTime = 'date_time';

    /**
     * Serialize one validated runtime value into its canonical storage form.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function serialize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Boolean => $this->serializeBoolean($value),
            self::Integer => $this->serializeInteger($value),
            self::Decimal => $this->serializeDecimal($value),
            self::Text,
            self::Enum => $this->serializeString($value),
            self::Json => $this->serializeJson($value),
            self::Date => $this->serializeDate($value),
            self::DateTime => $this->serializeDateTime($value),
        };
    }

    /**
     * Deserialize one canonical storage value into its runtime representation.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function deserialize(?string $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($this) {
            self::Boolean => $this->deserializeBoolean($raw),
            self::Integer => $this->deserializeInteger($raw),
            self::Text,
            self::Decimal,
            self::Enum => $raw,
            self::Json => $this->deserializeJson($raw),
            self::Date => $this->parseDate($raw),
            self::DateTime => $this->parseDateTime($raw),
        };
    }

    /**
     * Return baseline Laravel validation rules for this type.
     *
     * @return list<string>
     */
    public function baseRules(): array
    {
        return match ($this) {
            self::Boolean => ['boolean'],
            self::Integer => ['integer'],
            self::Decimal => ['numeric'],
            self::Json => ['array'],
            self::Date => ['date'],
            self::DateTime => ['date'],
            self::Text,
            self::Enum => ['string'],
        };
    }

    /**
     * Serialize one boolean value.
     */
    private function serializeBoolean(mixed $value): string
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException('Boolean settings require a boolean value.');
        }

        return $value ? '1' : '0';
    }

    /**
     * Serialize one integer value.
     */
    private function serializeInteger(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (string) (int) $value;
        }

        throw new InvalidArgumentException('Integer settings require an integer value.');
    }

    /**
     * Serialize one finite decimal value.
     */
    private function serializeDecimal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value) && is_finite($value)) {
            return (string) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Decimal settings require a finite numeric value.');
    }

    /**
     * Serialize one textual value.
     */
    private function serializeString(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("Settings of type [{$this->value}] require a string value.");
        }

        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException(
                "Settings of type [{$this->value}] require valid UTF-8 text.",
            );
        }

        return $value;
    }

    /**
     * Serialize one JSON object or list.
     *
     * @throws JsonException
     */
    private function serializeJson(mixed $value): string
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('JSON settings require an array value.');
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * Serialize one calendar date.
     */
    private function serializeDate(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->format('Y-m-d');
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Date settings require a Y-m-d value.');
        }

        return $this->parseDate($value)->format('Y-m-d');
    }

    /**
     * Serialize one timezone-aware date-time in UTC.
     */
    private function serializeDateTime(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $this->formatDateTime(CarbonImmutable::instance($value));
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Date-time settings require an ISO 8601 value with a timezone.');
        }

        return $this->formatDateTime($this->parseDateTime($value));
    }

    /**
     * Deserialize one canonical boolean.
     */
    private function deserializeBoolean(string $raw): bool
    {
        return match ($raw) {
            '0' => false,
            '1' => true,
            default => throw new InvalidArgumentException('Stored boolean settings must be encoded as 0 or 1.'),
        };
    }

    /**
     * Deserialize one canonical integer.
     */
    private function deserializeInteger(string $raw): int
    {
        if (filter_var($raw, FILTER_VALIDATE_INT) === false
            || (string) (int) $raw !== $raw) {
            throw new InvalidArgumentException('Stored integer settings must use canonical integer encoding.');
        }

        return (int) $raw;
    }

    /**
     * Deserialize one JSON object or list.
     *
     * @return array<mixed>
     *
     * @throws JsonException
     */
    private function deserializeJson(string $raw): array
    {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Stored JSON settings must decode to an array.');
        }

        return $decoded;
    }

    /**
     * Parse one strict calendar date.
     */
    private function parseDate(string $value): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('Date settings require a Y-m-d value.');
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Date settings require a valid Y-m-d value.');
        }

        return $date;
    }

    /**
     * Parse one strict timezone-aware ISO 8601 date-time.
     */
    private function parseDateTime(string $value): CarbonImmutable
    {
        if (preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/',
            $value,
        ) !== 1) {
            throw new InvalidArgumentException(
                'Date-time settings require an ISO 8601 value with a timezone.',
            );
        }

        try {
            $parsed = CarbonImmutable::parse($value);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'Date-time settings require a valid ISO 8601 value with a timezone.',
                previous: $exception,
            );
        }

        $normalized = str_ends_with($value, 'Z')
            ? substr($value, 0, -1).'+00:00'
            : $value;

        if (str_contains($normalized, '.')) {
            $normalized = preg_replace_callback(
                '/\.(\d{1,6})(?=[+-]\d{2}:\d{2}$)/',
                static fn (array $matches): string => '.'.str_pad($matches[1], 6, '0'),
                $normalized,
            );
            $matchesInput = is_string($normalized)
                && $parsed->format('Y-m-d\TH:i:s.uP') === $normalized;
        } else {
            $matchesInput = $parsed->format('Y-m-d\TH:i:sP') === $normalized;
        }

        if (! $matchesInput) {
            throw new InvalidArgumentException(
                'Date-time settings require a valid ISO 8601 value with a timezone.',
            );
        }

        return $parsed;
    }

    /**
     * Format one date-time canonically in UTC without losing microseconds.
     */
    private function formatDateTime(CarbonImmutable $value): string
    {
        $utc = $value->utc();

        return $utc->format('u') === '000000'
            ? $utc->format('Y-m-d\TH:i:sP')
            : $utc->format('Y-m-d\TH:i:s.uP');
    }
}
