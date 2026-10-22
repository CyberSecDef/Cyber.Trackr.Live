<?php

/*
 * Exit non-zero unless the app in <dir> would boot as APP_ENV=prod with
 * debug off, resolving the .env files exactly as Symfony does at runtime.
 *
 *   php bin/assert-prod-env.php [dir]      (dir defaults to the app root)
 *
 * bin/deploy.sh runs this first (review S11). The committed .env defaults
 * to APP_ENV=dev, and vendor/ ships with the dev bundles (profiler, debug),
 * so prod is only safe while its own untracked .env says prod. If that file
 * is lost or overwritten, deploy stops here instead of going live in dev.
 */

$dir = rtrim($argv[1] ?? dirname(__DIR__), '/');
if (!is_file($dir . '/vendor/autoload.php') || !is_file($dir . '/.env')) {
    fwrite(STDERR, "[assert-prod-env] {$dir} has no vendor/autoload.php or .env\n");
    exit(2);
}
require $dir . '/vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv($dir . '/.env');

$env   = (string) ($_SERVER['APP_ENV'] ?? '');
$debug = filter_var($_SERVER['APP_DEBUG'] ?? '0', FILTER_VALIDATE_BOOL);

if ($env !== 'prod' || $debug) {
    fwrite(STDERR, sprintf(
        "[assert-prod-env] REFUSING: %s resolves to APP_ENV=%s, APP_DEBUG=%s. Prod must be APP_ENV=prod with debug off; check its .env / .env.local.\n",
        $dir, $env === '' ? '(unset)' : $env, $debug ? 'on' : 'off'
    ));
    exit(1);
}

echo "[assert-prod-env] ok: APP_ENV=prod, debug off\n";
