<?php

// Enable error reporting to catch issues during worker bootstrap
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Log startup progress to see if we reached this point in the container logs
file_put_contents('php://stderr', "FrankenPHP worker booting...\n");

// Set a default for the application base path and public path if they are missing...
$_SERVER['APP_BASE_PATH'] = $_ENV['APP_BASE_PATH'] ?? $_SERVER['APP_BASE_PATH'] ?? __DIR__.'/..';
$_SERVER['APP_PUBLIC_PATH'] = $_ENV['APP_PUBLIC_PATH'] ?? $_SERVER['APP_PUBLIC_PATH'] ?? __DIR__;

file_put_contents('php://stderr', "Handing off to Octane binary...\n");

require __DIR__.'/../vendor/laravel/octane/bin/frankenphp-worker.php';
