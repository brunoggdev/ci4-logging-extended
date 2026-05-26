<?php

declare(strict_types=1);

$logViewerConfig = config('LoggingExtended');

if (! $logViewerConfig->viewer['enabled']) {
    return;
}

$path = trim($logViewerConfig->viewer['routesPath'], '/');

$routes->group($path, ['namespace' => 'Brunoggdev\LoggingExtended\Controllers'], static function ($routes) {
    $routes->get('/', 'LogViewerController::index');
    $routes->get('stream', 'LogViewerController::stream');
    $routes->get('(:segment)', 'LogViewerController::show/$1');
    $routes->post('(:segment)/delete', 'LogViewerController::delete/$1');
    $routes->post('delete-multiple', 'LogViewerController::deleteMultiple');
});
