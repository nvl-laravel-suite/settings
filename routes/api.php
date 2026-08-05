<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Settings\Http\Controllers\SettingsManagementController;
use Nvl\Settings\Support\SettingsRouteConfiguration;

$middleware = array_values(array_filter(
    (array) config('settings.management.middleware', ['api', 'auth']),
    static fn (mixed $value): bool => is_string($value) && $value !== '',
));

Route::middleware($middleware)
    ->prefix(SettingsRouteConfiguration::path())
    ->name(SettingsRouteConfiguration::name())
    ->group(function (): void {
        Route::get('/status', [SettingsManagementController::class, 'status'])->name('status');
        Route::get('/', [SettingsManagementController::class, 'index'])->name('index');
        Route::get('/{key}', [SettingsManagementController::class, 'show'])->name('show');
        Route::put('/{key}', [SettingsManagementController::class, 'update'])->name('update');
        Route::delete('/{key}', [SettingsManagementController::class, 'reset'])->name('reset');
    });
