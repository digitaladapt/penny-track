<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    // bootEnv loads .env first; if it's missing (e.g. fresh clone, CI),
    // fall back to .env.test so tests always have the vars they need.
    $envFile = dirname(__DIR__).'/.env';
    if (is_file($envFile)) {
        (new Dotenv())->bootEnv($envFile);
    } else {
        (new Dotenv())->bootEnv(dirname(__DIR__).'/.env.test');
    }
}

// Ensure the test database directory exists (SQLite needs the parent dir)
// In Docker this is handled by entrypoint.sh, but in CI nothing creates it.
$testDbDir = dirname(__DIR__).'/var/data';
if (!is_dir($testDbDir)) {
    mkdir($testDbDir, 0777, true);
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
