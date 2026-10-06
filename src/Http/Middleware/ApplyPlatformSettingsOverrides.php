<?php

declare(strict_types=1);

namespace Nvl\Settings\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nvl\Settings\Services\PlatformSettingsRuntime;
use Symfony\Component\HttpFoundation\Response;

/** Applies explicitly enabled platform settings for one HTTP request. */
final readonly class ApplyPlatformSettingsOverrides
{
    /** Retain the current request's scoped override projection. */
    public function __construct(private PlatformSettingsRuntime $runtime) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->runtime->run(static fn (): Response => $next($request));
    }
}
