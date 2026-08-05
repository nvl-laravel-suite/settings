<?php

declare(strict_types=1);

namespace Nvl\Settings\Commands;

use Illuminate\Console\Command;
use Nvl\Settings\Actions\ValidateSettingsSourcesAction;
use Nvl\Settings\Exceptions\SettingException;

/**
 * Validates configured PHP and JSON setting sources without persistence.
 */
final class ValidateCommand extends Command
{
    protected $signature = 'nvl:settings:validate
        {--format=text : Output format: text or json}';

    protected $description = 'Validate discovered PHP and JSON settings definitions';

    /**
     * Validate source discovery, parsing, defaults, and definition uniqueness.
     */
    public function handle(ValidateSettingsSourcesAction $action): int
    {
        $format = $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        try {
            $status = $action->execute();
        } catch (SettingException $exception) {
            if ($format === 'json') {
                $this->line((string) json_encode([
                    'valid' => false,
                    'error' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($format === 'json') {
            $this->line((string) json_encode(
                $status->toArray(),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
            ));
        } else {
            $this->info(sprintf(
                'Validated %d definitions from %d source files.',
                $status->definitionCount,
                $status->sourceCount,
            ));
            $this->line("Checksum: {$status->checksum}");
        }

        return self::SUCCESS;
    }
}
