<?php

declare(strict_types=1);

$logViewerConfig = config('LoggingExtended');

if (! $logViewerConfig->viewer['enabled']) {
    return;
}

$path    = trim($logViewerConfig->viewer['routes']['path'], '/');
$filters = $logViewerConfig->viewer['routes']['filters'];

// CI4's route group accepts a single string or array for 'filter' (note: singular key, array value is fine)
$groupOptions = ['namespace' => 'Brunoggdev\LoggingExtended\Controllers'];

if ($filters !== []) {
    $groupOptions['filter'] = $filters;
}

$routes->group($path, $groupOptions, static function ($routes) {
    $routes->get('/', 'LogViewerController::index');
    $routes->get('login', 'LogViewerController::login');
    $routes->post('login', 'LogViewerController::login');
    $routes->get('stream', 'LogViewerController::stream');
    $routes->get('(:segment)', 'LogViewerController::show/$1');
    $routes->post('(:segment)/delete', 'LogViewerController::delete/$1');
    $routes->post('delete-multiple', 'LogViewerController::deleteMultiple');
});
