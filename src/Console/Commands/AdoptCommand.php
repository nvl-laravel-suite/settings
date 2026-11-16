<?php

declare(strict_types=1);

namespace Nvl\Settings\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Nvl\Settings\Services\SettingsAdopter;
use Nvl\Support\Enums\SystemActor;

/**
 * Plans or applies a manifest-driven import from staged legacy settings.
 */
final class AdoptCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:settings:adopt
        {manifest : Absolute or application-relative JSON manifest path}
        {--apply : Persist all mapped overrides after successful validation}
        {--verify : Reconcile the committed target against the reviewed fingerprint}
        {--format=text : Output text or json}';

    /** @var string */
    protected $description = 'Plan or apply a reconciled legacy Settings adoption manifest';

    /**
     * Execute the adoption plan or apply phase.
     */
    public function handle(SettingsAdopter $adopt): int
    {
        $format = $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException('The nvl:settings:adopt format must be text or json.');
        }

        $manifestPath = $this->argument('manifest');
        $path = str_starts_with($manifestPath, DIRECTORY_SEPARATOR)
            ? $manifestPath
            : base_path($manifestPath);
        $maximumBytes = config('nvl-settings.adoption.maximum_manifest_bytes', 1_048_576);

        if (! is_int($maximumBytes) || $maximumBytes < 1) {
            throw new InvalidArgumentException('settings.adoption.maximum_manifest_bytes must be a positive integer.');
        }

        $size = is_file($path) ? filesize($path) : false;

        if (! is_int($size) || $size > $maximumBytes) {
            throw new InvalidArgumentException(
                "Settings adoption manifest [{$path}] is missing or exceeds {$maximumBytes} bytes.",
            );
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new InvalidArgumentException("Settings adoption manifest [{$path}] cannot be read.");
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                "Settings adoption manifest [{$path}] is not valid JSON.",
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('The Settings adoption manifest must be a JSON object.');
        }

        /** @var array<array-key, mixed> $decoded */
        $apply = (bool) $this->option('apply');
        $verify = (bool) $this->option('verify');
        if ($apply && $verify) {
            throw new InvalidArgumentException('Choose one Settings adoption phase.');
        }
        $result = $verify ? $adopt->verify($decoded) : $adopt->execute($decoded, apply: $apply, actor: $apply ? SystemActor::Background : null);

        if ($format === 'json') {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->components->info(match ($result['mode']) {
                'plan' => $result['conflicts'] === [] ? 'Settings adoption plan is valid; no data was changed.' : 'Settings adoption plan has conflicts.',
                'verify' => 'Committed Settings adoption has been reconciled.',
                default => 'Settings adoption completed and reconciled.',
            });
            $this->line('Fingerprint '.$result['fingerprint']);
            $counts = $result['reconciliation'];
            $this->table(
                ['Expected', 'Source', 'Mapped', 'Matched', 'Created', 'Updated', 'Unchanged'],
                [[
                    $counts['expected'],
                    $counts['source'],
                    $counts['mapped'],
                    $counts['matched'],
                    $counts['created'],
                    $counts['updated'],
                    $counts['unchanged'],
                ]],
            );
            foreach ($result['orphans'] as $orphan) {
                $this->components->warn('Retained legacy orphan '.json_encode($orphan, JSON_THROW_ON_ERROR));
            }
            foreach ($result['conflicts'] as $conflict) {
                $this->components->error($conflict);
            }
        }

        return $result['conflicts'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
