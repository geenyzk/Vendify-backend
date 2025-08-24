<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
ini_set('memory_limit', '2048M');

// Determine if the application is in maintenance mode...
<<<<<<< HEAD
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
=======
<<<<<<< HEAD
if (file_exists($maintenance = __DIR__ . '/../storage/framework/maintenance.php')) {
=======
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
>>>>>>> 5a8861e (Jush)
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
    require $maintenance;
}

// Register the Composer autoloader...
<<<<<<< HEAD
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';
=======
<<<<<<< HEAD
require __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__ . '/../bootstrap/app.php';
=======
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';
>>>>>>> 5a8861e (Jush)
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4

$app->handleRequest(Request::capture());
