<?php
// Dev router for the `php -S` server Playwright boots. Serves existing files
// in public/ as static assets and routes everything else through the Symfony
// front controller — so /css/app.css and friends behave like they do behind
// nginx in production, instead of being pushed through the framework.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Server-side request log for report_generator_privacy.spec.js. Requests
// carrying an e2e_run cookie (page, Web Workers, image beacons: everything
// same-origin) are appended to test-results/e2e-requests-<run>.log, so the
// test can see exactly what reached the server. Playwright itself can't see
// requests made from inside Web Workers.
$run = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_COOKIE['e2e_run'] ?? ''));
if ($run !== '') {
    $dir = __DIR__ . '/../test-results';
    @mkdir($dir, 0777, true);
    $body = (string) file_get_contents('php://input');
    file_put_contents("$dir/e2e-requests-$run.log", json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri'    => $_SERVER['REQUEST_URI'] ?? '',
        'bytes'  => strlen($body),
        'body'   => substr($body, 0, 2000),
    ]) . "\n", FILE_APPEND | LOCK_EX);
}
$file = __DIR__ . '/../public' . $path;

if ($path !== '/' && is_file($file)) {
    return false; // built-in server serves the static file
}

require __DIR__ . '/../public/index.php';
