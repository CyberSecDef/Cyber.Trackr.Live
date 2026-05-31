<?php
// Dev router for the `php -S` server Playwright boots. Serves existing files
// in public/ as static assets and routes everything else through the Symfony
// front controller — so /css/app.css and friends behave like they do behind
// nginx in production, instead of being pushed through the framework.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/../public' . $path;

if ($path !== '/' && is_file($file)) {
    return false; // built-in server serves the static file
}

require __DIR__ . '/../public/index.php';
