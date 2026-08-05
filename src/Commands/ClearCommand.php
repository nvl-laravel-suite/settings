<?php

declare(strict_types=1);

namespace Nvl\Settings\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Nvl\Settings\Support\DefinitionRepository;

/**
 * Removes the generated definition discovery map.
 */
final class ClearCommand extends Command
{
    protected $signature = 'nvl:settings:clear';

    protected $description = 'Clear the cached settings definition map';

    /**
     * Remove the cache file when it exists.
     */
    public function handle(
        DefinitionRepository $repository,
        Filesystem $filesystem,
    ): int {
        $path = $repository->cachePath();
        $filesystem->delete($path);

        $this->info('Settings definition map cache cleared.');

        return self::SUCCESS;
    }
}
