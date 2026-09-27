<?php
use Illuminate\Http\Request;
define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/app.php';
$app->handleRequest(Request::capture());
