<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
ini_set('memory_limit', '2048M');

// A browser preflight needs no application state. When production config is
// cached, answer valid OPTIONS requests before Composer/Laravel/session/DB
// boot. If config is not cached or the request is not explicitly allowed, let
// Laravel's normal CORS middleware decide instead of weakening the policy.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    $cachedConfigPath = __DIR__ . '/../bootstrap/cache/config.php';
    if (is_file($cachedConfigPath)) {
        $cachedConfig = require $cachedConfigPath;
        $cors = $cachedConfig['cors'] ?? [];
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $method = strtoupper($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? '');
        $requestedHeaders = array_values(array_filter(array_map(
            static fn (string $header): string => strtolower(trim($header)),
            explode(',', $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '')
        )));
        $allowedOrigins = $cors['allowed_origins'] ?? [];
        $allowedMethods = array_map('strtoupper', $cors['allowed_methods'] ?? []);
        $allowedHeaders = array_map('strtolower', $cors['allowed_headers'] ?? []);

        $headersAllowed = count(array_diff($requestedHeaders, $allowedHeaders)) === 0;
        if (in_array($origin, $allowedOrigins, true)
            && in_array($method, $allowedMethods, true)
            && $headersAllowed) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: ' . implode(', ', $cors['allowed_methods']));
            header('Access-Control-Allow-Headers: ' . implode(', ', $cors['allowed_headers']));
            header('Access-Control-Max-Age: ' . (int) ($cors['max_age'] ?? 600));
            header('Vary: Origin, Access-Control-Request-Method, Access-Control-Request-Headers');
            http_response_code(204);
            exit;
        }
    }
}


if (file_exists($maintenance = __DIR__ . '/../storage/framework/maintenance.php')) {

    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__ . '/../bootstrap/app.php';


$app->handleRequest(Request::capture());
