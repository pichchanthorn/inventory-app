# Automated test suite (J1 - P0 regression coverage)

Covers stock integrity, POS/sales, customer debt, RBAC, CSRF, reference
generation, and migration/schema integrity. See the project's J1
pre-implementation analysis for the full test matrix and rationale.

## Prerequisites

- PHP 8.1+ with `pdo_mysql` (already required by the app itself).
- Composer (`composer install`).
- A MySQL/MariaDB server reachable from this machine.
- **Two empty, dedicated test databases** and a MySQL/MariaDB user that
  can create/drop tables in them (nothing more - it does not need
  `CREATE DATABASE` or any privilege outside these two schemas):

  ```sql
  CREATE DATABASE inventory_test CHARACTER SET utf8mb4;
  CREATE DATABASE inventory_test_migrations CHARACTER SET utf8mb4;
  CREATE USER 'test_user'@'127.0.0.1' IDENTIFIED BY 'test_pass';
  GRANT ALL PRIVILEGES ON inventory_test.* TO 'test_user'@'127.0.0.1';
  GRANT ALL PRIVILEGES ON inventory_test_migrations.* TO 'test_user'@'127.0.0.1';
  FLUSH PRIVILEGES;
  ```

  `inventory_test` is rebuilt fresh from `database/schema.sql` once per
  suite run and used by every Unit/Integration/Concurrency/Http test.
  `inventory_test_migrations` is a second, separate scratch database used
  **only** by `tests/Schema/MigrationIntegrityTest.php`, to replay
  migrations 001-013 in isolation from everything else.

## Test database safety

The suite reads its connection details purely from environment
variables - the exact same `DB_HOST`/`DB_DATABASE`/`DB_USERNAME`/
`DB_PASSWORD` variables `config/db.php` already supports, no application
code changes needed. Defaults (used when a variable is not set) point at
the dedicated test databases above, never at a development or production
database name.

`tests/bootstrap.php` refuses to run at all unless **both** `APP_ENV=test`
**and** `DB_DATABASE` contains the substring `test`. This is a hard
backstop, not a convenience default - it exists specifically so a
misconfigured environment can never cause the suite to run against a real
database. If you see "REFUSING TO RUN TESTS", check your environment
variables.

To point the suite at a different host/user/password (e.g. in CI), set:

```
APP_ENV=test
DB_HOST=127.0.0.1
DB_DATABASE=inventory_test
DB_USERNAME=test_user
DB_PASSWORD=test_pass
DB_DATABASE_MIGRATIONS_TEST=inventory_test_migrations   # optional, defaults shown
```

## Running the suite

```
composer install
composer test
```

(`composer test` runs `phpunit`; you can also run `vendor/bin/phpunit`
directly, or target one suite: `vendor/bin/phpunit --testsuite=integration`.)

Each run rebuilds `inventory_test` from scratch (drops every table, then
re-applies `database/schema.sql`) before any test executes, so the suite
is repeatable - running it twice in a row produces the same result. Most
tests additionally wrap themselves in their own database transaction,
rolled back in `tearDown()`, so they cannot leak data into each other.
The concurrency tests (`tests/Concurrency/`) and HTTP tests
(`tests/Http/`) are the exception - they need genuinely committed rows
visible to a second connection/process, so they commit their own seed
data directly and remove it again in `tearDown()`.

## What the different test groups need

- `tests/Unit`, `tests/Integration` - only the database connection above.
- `tests/Concurrency` - additionally launches short-lived standalone PHP
  CLI worker scripts (`stock_out_race.php`, `debt_payment_race.php`,
  `reference_race.php`) as separate OS processes, to exercise the
  database's own row-locking under genuine concurrent connections. No
  extra setup needed - PHPUnit launches them itself via `proc_open()`.
- `tests/Http` - starts PHP's built-in web server (`php -S 127.0.0.1:8971`)
  against the real application files for the duration of that test class,
  and drives it with real HTTP requests (via the `curl` PHP extension).
  Port `8971` must be free on the machine running the suite.
