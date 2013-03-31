<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Actions\PrepareMigrationScreenshotDetailAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->get('/screenshot-fixtures/migration-assistant/reviewed-detail', static function (): RedirectResponse {
    $session = app(PrepareMigrationScreenshotDetailAction::class)->handle();
    $routeKey = $session->getRouteKey();
    throw_unless(is_int($routeKey) || is_string($routeKey), RuntimeException::class, 'The migration screenshot session route key must be scalar.');

    return redirect('/admin/migration-assistant/import-sessions/' . $routeKey);
});
