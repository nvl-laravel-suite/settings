<?php

declare(strict_types=1);

namespace Nvl\Settings\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Nvl\Settings\Support\DefinitionRepository;

/**
 * Writes the validated deterministic definition-path map to bootstrap cache.
 */
final class CacheCommand extends Command
{
    protected $signature = 'nvl:settings:cache';

    protected $description = 'Cache the settings definition map';

    /**
     * Validate all sources before atomically replacing the cached map.
     */
    public function handle(
        DefinitionRepository $repository,
        Filesystem $filesystem,
    ): int {
        $map = $repository->refresh();
        $path = $repository->cachePath();
        $content = "<?php\n\nreturn ".var_export($map, true).";\n";

        $filesystem->replace($path, $content);
        $this->info('Settings definition map cached successfully.');

        return self::SUCCESS;
    }
}
