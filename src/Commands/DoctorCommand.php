<?php

declare(strict_types=1);

namespace Nvl\Settings\Commands;

use Illuminate\Console\Command;
use Nvl\Settings\Services\SettingsDoctor;

/**
 * Reports Settings package readiness without mutation.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'nvl:settings:doctor
        {--strict : Treat warnings as failures}
        {--format=text : Output format: text or json}';

    protected $description = 'Inspect Settings schema and definition readiness';

    /**
     * Render every readiness check and return the appropriate exit code.
     */
    public function handle(SettingsDoctor $doctor): int
    {
        $checks = $doctor->inspect();
        $strict = (bool) $this->option('strict');
        $failed = collect($checks)->contains(
            static fn ($check): bool => ! $check->passed
                && ($strict || $check->severity === 'error'),
        );

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode([
                'healthy' => ! $failed,
                'checks' => array_map(
                    static fn ($check): array => [
                        'key' => $check->key,
                        'severity' => $check->severity,
                        'passed' => $check->passed,
                        'message' => $check->message,
                    ],
                    $checks,
                ),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $check) {
                $this->line(sprintf(
                    '[%s] %s: %s',
                    $check->passed ? 'PASS' : strtoupper($check->severity),
                    $check->key,
                    $check->message,
                ));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
