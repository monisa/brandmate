<?php

// Entry point for the API on shared hosting, where the web root is fixed.
// The Laravel code lives in a folder called "brandmate-app" OUTSIDE the web
// root, so none of it (including .env) can be reached from the internet.

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Main domain:  domains/example.com/public_html            -> ../brandmate-app
// Subdomain:    domains/example.com/public_html/subfolder  -> ../../brandmate-app
$app_dir = null;
foreach ([__DIR__.'/../brandmate-app', __DIR__.'/../../brandmate-app'] as $candidate) {
    if (is_file($candidate.'/bootstrap/app.php')) {
        $app_dir = $candidate;
        break;
    }
}

if ($app_dir === null) {
    http_response_code(503);
    exit('The application folder "brandmate-app" was not found next to public_html.');
}

if (file_exists($maintenance = $app_dir.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $app_dir.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $app_dir.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
