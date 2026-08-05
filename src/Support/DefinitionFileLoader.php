<?php

declare(strict_types=1);

namespace Nvl\Settings\Support;

use JsonException;
use Nvl\Settings\Exceptions\InvalidDefinitionException;
use Throwable;

/**
 * Reads bounded PHP or JSON setting-definition files from trusted source paths.
 */
final class DefinitionFileLoader
{
    /**
     * Read one definition file into a string-keyed source document.
     *
     * @return array<string, mixed>
     */
    public function load(string $path): array
    {
        $realPath = realpath($path);

        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            throw new InvalidDefinitionException(
                "Settings definition file [{$path}] is missing or unreadable.",
            );
        }

        $maximumBytes = config('settings.discovery.maximum_file_bytes', 262_144);
        $maximumBytes = is_int($maximumBytes) && $maximumBytes > 0
            ? $maximumBytes
            : 262_144;
        $size = filesize($realPath);

        if (! is_int($size) || $size > $maximumBytes) {
            throw new InvalidDefinitionException(
                "Settings definition file [{$realPath}] exceeds {$maximumBytes} bytes.",
            );
        }

        $data = match (true) {
            str_ends_with($realPath, '.settings.php') => $this->loadPhp($realPath),
            str_ends_with($realPath, '.settings.json') => $this->loadJson($realPath),
            default => throw new InvalidDefinitionException(
                "Unsupported settings definition file [{$realPath}].",
            ),
        };

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidDefinitionException(
                "Settings definition file [{$realPath}] must contain an object.",
            );
        }

        $normalized = [];

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidDefinitionException(
                    "Settings definition file [{$realPath}] must use string keys.",
                );
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Execute one trusted PHP definition file in an isolated closure.
     */
    private function loadPhp(string $path): mixed
    {
        try {
            return (static fn (string $definitionFile): mixed => require $definitionFile)($path);
        } catch (Throwable $throwable) {
            throw new InvalidDefinitionException(
                "Settings definition file [{$path}] could not be loaded: {$throwable->getMessage()}",
                previous: $throwable,
            );
        }
    }

    /**
     * Decode one bounded JSON definition document.
     *
     * @return array<array-key, mixed>
     */
    private function loadJson(string $path): array
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new InvalidDefinitionException(
                "Settings definition file [{$path}] could not be read.",
            );
        }

        $maximumDepth = config('settings.discovery.maximum_json_depth', 64);
        $maximumDepth = is_int($maximumDepth) && $maximumDepth > 0
            ? $maximumDepth
            : 64;

        try {
            $decoded = json_decode(
                $contents,
                true,
                $maximumDepth,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidDefinitionException(
                "Settings definition file [{$path}] contains invalid JSON: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new InvalidDefinitionException(
                "Settings definition file [{$path}] must contain a JSON object.",
            );
        }

        return $decoded;
    }
}
