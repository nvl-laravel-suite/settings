<?php

declare(strict_types=1);

namespace Nvl\Settings\Support;

use BadMethodCallException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Exceptions\DuplicateSettingException;
use Nvl\Settings\Exceptions\InvalidDefinitionException;
use Nvl\Settings\Exceptions\UnknownSettingException;
use Symfony\Component\Finder\Finder;
use TypeError;

/**
 * Discovers, validates, and caches file-backed setting definitions.
 */
final class DefinitionRepository
{
    public const int MAX_SEGMENT_LENGTH = 100;

    public const int MAX_FULL_KEY_LENGTH = 302;

    /** @var array<string, Definition>|null  keyed by "namespace.scope.key" */
    protected ?array $definitions = null;

    protected bool $faked = false;

    /**
     * Create the file-backed definition repository.
     */
    public function __construct(
        private readonly DefinitionFileLoader $files,
        private readonly Application $application,
    ) {}

    /**
     * Return all definitions keyed by canonical full key.
     *
     * @return array<string, Definition>
     */
    public function all(): array
    {
        return $this->definitions ??= $this->load();
    }

    /**
     * Return one definition or fail for an unknown key.
     */
    public function get(string $fullKey): Definition
    {
        return $this->all()[$fullKey]
            ?? throw new UnknownSettingException("Setting [$fullKey] is not defined.");
    }

    /**
     * @return array<string, string> namespace => absolute file path
     */
    public function map(): array
    {
        if (config('settings.discovery.cache') && file_exists($path = $this->cachePath())) {
            $cached = require $path;

            if (! is_array($cached)) {
                throw new InvalidDefinitionException("Cached definition map [{$path}] must return an array.");
            }

            $normalized = [];

            foreach ($cached as $namespace => $source) {
                if (! is_string($namespace)
                    || ! $this->validSegment($namespace)
                    || ! is_string($source)
                    || $source === '') {
                    throw new InvalidDefinitionException(
                        "Cached definition map [{$path}] contains an invalid source.",
                    );
                }

                $normalized[$namespace] = $source;
            }

            ksort($normalized);

            return $normalized;
        }

        return $this->discover();
    }

    /**
     * Rediscover and validate source files without consulting an existing cache.
     *
     * @return array<string, string> namespace => absolute file path
     */
    public function refresh(): array
    {
        if ($this->faked) {
            return [];
        }

        $map = $this->discover();
        $this->definitions = $this->load($map);

        return $map;
    }

    /**
     * Load and validate definitions from the discovery map.
     *
     * @param  array<string, string>|null  $map
     * @return array<string, Definition>
     */
    protected function load(?array $map = null): array
    {
        $map ??= $this->map();
        $definitions = [];

        foreach ($map as $ns => $file) {
            $data = $this->files->load($file);
            $unknownSourceKeys = array_diff(
                array_keys($data),
                ['namespace', 'settings', 'scopes'],
            );

            if ($unknownSourceKeys !== []) {
                throw new InvalidDefinitionException(
                    "Settings file [{$file}] contains unknown keys: "
                    .implode(', ', $unknownSourceKeys).'.',
                );
            }

            $namespace = $data['namespace'] ?? $ns;

            if (! is_string($namespace) || ! $this->validSegment($namespace)) {
                throw new InvalidDefinitionException("Settings file [{$file}] declares an invalid namespace.");
            }

            if ($namespace !== $ns) {
                throw new InvalidDefinitionException(
                    "Settings file [{$file}] declares namespace [{$namespace}], "
                    ."which does not match filename namespace [{$ns}].",
                );
            }

            $scopes = $data['scopes'] ?? [];

            if (! is_array($scopes)) {
                throw new InvalidDefinitionException("Settings file [{$file}] scopes must be an array.");
            }

            if (isset($data['settings'])) {
                if (array_key_exists('', $scopes)) {
                    throw new InvalidDefinitionException(
                        "Settings file [{$file}] cannot declare both settings and an empty scope.",
                    );
                }

                if (! is_array($data['settings'])) {
                    throw new InvalidDefinitionException("Settings file [{$file}] settings must be an array.");
                }

                $scopes[''] = $data['settings'];
            }

            foreach ($scopes as $scope => $keys) {
                if (! is_string($scope) || ($scope !== '' && ! $this->validSegment($scope))) {
                    throw new InvalidDefinitionException("Settings namespace [{$namespace}] declares an invalid scope.");
                }

                if (! is_array($keys)) {
                    throw new InvalidDefinitionException("Settings scope [{$namespace}.{$scope}] must be an array.");
                }

                foreach ($keys as $key => $config) {
                    if (! is_string($key) || ! $this->validSegment($key) || ! is_array($config)) {
                        throw new InvalidDefinitionException("Settings namespace [{$namespace}] contains an invalid key definition.");
                    }

                    $fullKey = implode('.', array_filter(
                        [$namespace, $scope, $key],
                        static fn (string $segment): bool => $segment !== '',
                    ));

                    if (isset($definitions[$fullKey])) {
                        throw new DuplicateSettingException("Setting [$fullKey] is duplicated.");
                    }

                    $unknownDefinitionKeys = array_diff(
                        array_keys($config),
                        [
                            'type', 'default', 'description', 'rules',
                            'position', 'overrides', 'metadata',
                        ],
                    );

                    if ($unknownDefinitionKeys !== []) {
                        throw new InvalidDefinitionException(
                            "Setting [{$fullKey}] contains unknown keys: "
                            .implode(', ', $unknownDefinitionKeys).'.',
                        );
                    }

                    if (! array_key_exists('default', $config)) {
                        throw new InvalidDefinitionException(
                            "Setting [{$fullKey}] must declare an explicit default.",
                        );
                    }

                    $type = $this->settingType($config['type'] ?? null, $fullKey);

                    if (isset($config['rules']) && ! is_array($config['rules'])) {
                        throw new InvalidDefinitionException(
                            "Setting [{$fullKey}] rules must be an array.",
                        );
                    }

                    if (str_ends_with($file, '.settings.json')) {
                        if (isset($config['rules']) && ! array_is_list($config['rules'])) {
                            throw new InvalidDefinitionException(
                                "JSON setting [{$fullKey}] rules must be a list.",
                            );
                        }

                        foreach ($config['rules'] ?? [] as $rule) {
                            if (! is_string($rule)) {
                                throw new InvalidDefinitionException(
                                    "JSON setting [{$fullKey}] may only use string validation rules.",
                                );
                            }
                        }
                    }

                    if (isset($config['metadata']) && ! is_array($config['metadata'])) {
                        throw new InvalidDefinitionException(
                            "Setting [{$fullKey}] metadata must be an object.",
                        );
                    }

                    if (isset($config['overrides']) && (! is_string($config['overrides']) || trim($config['overrides']) === '')) {
                        throw new InvalidDefinitionException("Setting [$fullKey] overrides must be a non-empty config key.");
                    }

                    $description = $config['description'] ?? '';
                    $position = $config['position'] ?? 0;
                    $overrides = $config['overrides'] ?? null;
                    $rules = $config['rules'] ?? [];

                    if (is_string($overrides) && ! config()->has($overrides)) {
                        throw new InvalidDefinitionException(
                            "Setting [{$fullKey}] targets unknown config key [{$overrides}].",
                        );
                    }
                    $this->validateRules($fullKey, array_values($rules));

                    if (! is_string($description) || ! is_int($position)) {
                        throw new InvalidDefinitionException("Setting [$fullKey] contains invalid metadata.");
                    }

                    $metadata = [];

                    foreach (is_array($config['metadata'] ?? null) ? $config['metadata'] : [] as $metadataKey => $metadataValue) {
                        if (! is_string($metadataKey)) {
                            throw new InvalidDefinitionException("Setting [$fullKey] metadata keys must be strings.");
                        }

                        $metadata[$metadataKey] = $metadataValue;
                    }

                    $this->validateDefault(
                        $fullKey,
                        $config['default'],
                        $type,
                        array_values($rules),
                    );

                    $definition = new Definition(
                        namespace: (string) $namespace,
                        scope: (string) $scope,
                        key: (string) $key,
                        type: $type,
                        default: $config['default'] ?? null,
                        description: $description,
                        rules: array_values($rules),
                        position: $position,
                        overrides: $overrides,
                        metadata: $metadata,
                        source: $file,
                    );
                    $definition->hash();
                    $definitions[$fullKey] = $definition;
                }
            }
        }

        return $definitions;
    }

    /**
     * Discover definition files from configured paths.
     *
     * @return array<string, string>
     */
    protected function discover(): array
    {
        $map = [];
        $configuredPaths = config('settings.discovery.paths', []);
        $paths = is_array($configuredPaths) ? $configuredPaths : [];
        $configuredPatterns = config('settings.discovery.patterns', [
            '*.settings.php',
            '*.settings.json',
        ]);
        $patterns = is_array($configuredPatterns)
            ? array_values(array_filter(
                $configuredPatterns,
                static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== '',
            ))
            : [];
        $patterns = $patterns !== []
            ? $patterns
            : ['*.settings.php', '*.settings.json'];
        $recursive = (bool) config('settings.discovery.recursive', true);
        $followLinks = (bool) config('settings.discovery.follow_links', false);
        $maximumFiles = config('settings.discovery.maximum_files', 1_000);
        $maximumFiles = is_int($maximumFiles) && $maximumFiles > 0 ? $maximumFiles : 1_000;
        sort($paths);
        $count = 0;

        foreach ($paths as $glob) {
            if (! is_string($glob)) {
                continue;
            }

            foreach (glob($glob, GLOB_ONLYDIR) ?: [] as $dir) {
                $root = realpath($dir);

                if ($root === false || ! is_dir($root)) {
                    continue;
                }

                $finder = Finder::create()
                    ->files()
                    ->in($root)
                    ->name($patterns)
                    ->sortByName();

                if (! $recursive) {
                    $finder->depth('== 0');
                }

                if ($followLinks) {
                    $finder->followLinks();
                }

                foreach ($finder as $file) {
                    $realPath = $file->getRealPath();

                    if ($realPath === ''
                        || (! $followLinks && is_link($file->getPathname()))
                        || ! str_starts_with($realPath, $root.DIRECTORY_SEPARATOR)) {
                        throw new InvalidDefinitionException(
                            "Settings definition path [{$file->getPathname()}] escapes its configured root.",
                        );
                    }

                    $count++;
                    if ($count > $maximumFiles) {
                        throw new InvalidDefinitionException(
                            "Settings discovery exceeds the configured {$maximumFiles}-file limit.",
                        );
                    }

                    $ns = $this->namespaceFor($realPath);

                    if (isset($map[$ns])) {
                        throw new DuplicateSettingException(
                            "Namespace [$ns] is declared by both [{$map[$ns]}] and [{$realPath}]."
                        );
                    }

                    $map[$ns] = $realPath;
                }
            }
        }

        ksort($map);

        return $map;
    }

    /**
     * Infer a namespace from a definition filename.
     */
    protected function namespaceFor(string $path): string
    {
        $namespace = preg_replace('/\.settings\.(?:php|json)$/', '', basename($path));

        if (! is_string($namespace) || ! $this->validSegment($namespace)) {
            throw new InvalidDefinitionException(
                "Settings definition file [{$path}] has an invalid filename namespace.",
            );
        }

        return $namespace;
    }

    /**
     * Return the application bootstrap cache path.
     */
    public function cachePath(): string
    {
        $configured = config('settings.discovery.cache_path');

        if ($configured === null) {
            $configured = $this->application->bootstrapPath('cache/nvl-settings.php');
        }

        if (! is_string($configured) || $configured === '') {
            throw new InvalidDefinitionException(
                'settings.discovery.cache_path must be an absolute path below bootstrap/cache.',
            );
        }

        $directory = realpath(dirname($configured));
        $bootstrapCache = realpath($this->application->bootstrapPath('cache'));

        if ($directory === false
            || $bootstrapCache === false
            || ($directory !== $bootstrapCache
                && ! str_starts_with($directory, $bootstrapCache.DIRECTORY_SEPARATOR))) {
            throw new InvalidDefinitionException(
                'settings.discovery.cache_path must remain below bootstrap/cache.',
            );
        }

        return $directory.DIRECTORY_SEPARATOR.basename($configured);
    }

    /**
     * Return a deterministic checksum of discovered paths and source contents.
     *
     * @param  array<string, string>|null  $map
     */
    public function checksum(?array $map = null): string
    {
        $sources = [];

        foreach ($map ?? $this->map() as $namespace => $path) {
            $digest = hash_file('sha256', $path);

            if (! is_string($digest)) {
                throw new InvalidDefinitionException(
                    "Settings definition file [{$path}] could not be hashed.",
                );
            }

            $sources[$namespace] = $digest;
        }

        return hash('sha256', (string) json_encode($sources, JSON_THROW_ON_ERROR));
    }

    /**
     * Replace discovered definitions for a test.
     *
     * @param  array<string, array{type: SettingType, default?: mixed, description?: string, rules?: array<int, mixed>, position?: int, overrides?: string|null, metadata?: array<string, mixed>}>  $definitions
     */
    public function fake(array $definitions): void
    {
        $this->faked = true;
        $this->definitions = [];

        foreach ($definitions as $fullKey => $config) {
            $parts = explode('.', $fullKey);
            $namespace = $parts[0];
            $scope = count($parts) === 3 ? $parts[1] : '';
            $key = $parts[count($parts) - 1];

            if (! in_array(count($parts), [2, 3], true)
                || ! $this->validSegment($namespace)
                || ($scope !== '' && ! $this->validSegment($scope))
                || ! $this->validSegment($key)) {
                throw new InvalidDefinitionException(
                    "Fake setting [{$fullKey}] must contain valid namespace, optional scope, and key segments.",
                );
            }

            $rules = array_values($config['rules'] ?? []);
            $this->validateRules($fullKey, $rules);
            $this->validateDefault(
                $fullKey,
                $config['default'] ?? null,
                $config['type'],
                $rules,
            );
            $definition = new Definition(
                namespace: $namespace,
                scope: $scope,
                key: $key,
                type: $config['type'],
                default: $config['default'] ?? null,
                description: $config['description'] ?? '',
                rules: $rules,
                position: $config['position'] ?? 0,
                overrides: $config['overrides'] ?? null,
                metadata: is_array($config['metadata'] ?? null) ? $config['metadata'] : [],
                source: 'fake',
            );
            $definition->hash();
            $this->definitions[$fullKey] = $definition;
        }
    }

    /**
     * Determine whether a canonical key segment is safe and unambiguous.
     */
    private function validSegment(string $segment): bool
    {
        return Str::length($segment) <= self::MAX_SEGMENT_LENGTH
            && preg_match('/^[A-Za-z0-9_-]+$/', $segment) === 1;
    }

    /**
     * Resolve a portable string or native enum setting type.
     */
    private function settingType(mixed $value, string $fullKey): SettingType
    {
        $type = $value instanceof SettingType
            ? $value
            : (is_string($value) ? SettingType::tryFrom($value) : null);

        if (! $type instanceof SettingType) {
            throw new InvalidDefinitionException(
                "Setting [{$fullKey}] must declare a valid SettingType or enum value.",
            );
        }

        return $type;
    }

    /**
     * Ensure the source-controlled fallback satisfies its declared runtime rules.
     *
     * @param  list<mixed>  $rules
     */
    private function validateDefault(
        string $fullKey,
        mixed $default,
        SettingType $type,
        array $rules,
    ): void {
        try {
            $validator = Validator::make(
                ['value' => $default],
                ['value' => [...$type->baseRules(), ...$rules]],
            );
        } catch (BadMethodCallException|InvalidArgumentException|TypeError $exception) {
            throw new InvalidDefinitionException(
                "Setting [{$fullKey}] declares invalid validation rules.",
                previous: $exception,
            );
        }

        if ($validator->fails()) {
            throw new InvalidDefinitionException(
                "Setting [{$fullKey}] has an invalid default: "
                .$validator->errors()->first('value'),
            );
        }

        try {
            $type->serialize($default);
        } catch (InvalidArgumentException|JsonException $exception) {
            throw new InvalidDefinitionException(
                "Setting [{$fullKey}] has an invalid default: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    /**
     * Ensure custom validation rules can be compiled independently of a default value.
     *
     * @param  list<mixed>  $rules
     */
    private function validateRules(string $fullKey, array $rules): void
    {
        try {
            Validator::make(
                ['value' => '__nvl_settings_rule_probe__'],
                ['value' => $rules],
            )->passes();
        } catch (BadMethodCallException|InvalidArgumentException|TypeError $exception) {
            throw new InvalidDefinitionException(
                "Setting [{$fullKey}] declares invalid validation rules.",
                previous: $exception,
            );
        }
    }
}
