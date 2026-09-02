<?php
declare(strict_types=1);

// ================================================
// J1 test suite bootstrap
//
// This is the ONLY thing standing between an automated test run and a
// real database if environment variables are ever misconfigured, so the
// safety check below is intentionally the first thing this file does,
// before config/db.php (and therefore any PDO connection) is even
// loaded.
// ================================================

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (getenv('APP_ENV') === false) {
    putenv('APP_ENV=test');
}
foreach ([
    'DB_HOST' => '127.0.0.1',
    'DB_DATABASE' => 'inventory_test',
    'DB_USERNAME' => 'test_user',
    'DB_PASSWORD' => 'test_pass',
] as $key => $default) {
    if (getenv($key) === false) {
        putenv("$key=$default");
    }
}

$appEnv = getenv('APP_ENV');
$dbName = (string) getenv('DB_DATABASE');

// Refuse to run unless APP_ENV is explicitly 'test' AND the configured
// database name itself contains 'test'. Both checks are required - an
// env var typo that flips only one of them must still be caught. This
// never silently falls back to a development/production-shaped name.
if ($appEnv !== 'test' || stripos($dbName, 'test') === false) {
    fwrite(STDERR,
        "\nREFUSING TO RUN TESTS.\n" .
        "APP_ENV=" . var_export($appEnv, true) . ", DB_DATABASE=" . var_export($dbName, true) . "\n" .
        "The test suite requires APP_ENV=test and a DB_DATABASE name that " .
        "contains 'test' (e.g. inventory_test). This check exists to make " .
        "it impossible for the suite to accidentally run against a real " .
        "development or production database. See tests/README.md.\n\n"
    );
    exit(1);
}

define('TESTS_ROOT', __DIR__);
define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/includes/lang.php';
require APP_ROOT . '/config/db.php'; // provides $pdo, using the env vars set above
require APP_ROOT . '/includes/stock.php';
require APP_ROOT . '/includes/debt.php';
require APP_ROOT . '/includes/currency.php';
require APP_ROOT . '/includes/audit.php';
require APP_ROOT . '/includes/backup.php';
require TESTS_ROOT . '/SchemaBuilder.php';
require TESTS_ROOT . '/TestCase.php';
require TESTS_ROOT . '/fixtures/seed_helpers.php';

// One fresh schema build for the whole run (not per-test - per-test
// isolation instead comes from TestCase's transaction-rollback wrapper,
// see TestCase.php). Every test in Unit/Integration/Http therefore starts
// from the exact structure database/schema.sql produces today.
$GLOBALS['__TEST_PDO'] = $pdo;
(new Tests\SchemaBuilder($pdo))->buildFreshFromSchema();
