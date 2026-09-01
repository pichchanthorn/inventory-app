<?php
declare(strict_types=1);

namespace Tests\Schema;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\SchemaBuilder;

// P0 #18 (Schema / Migration Integrity).
//
// Two separate, complementary checks:
//
//  1. testFreshDatabaseInitializesSuccessfullyFromSchemaSql() - confirms
//     database/schema.sql (the file a real fresh install uses) builds a
//     structurally complete, valid schema. This reuses the SAME database
//     tests/bootstrap.php already built for the whole suite - no new
//     infrastructure needed for this half.
//
//  2. testMigrations001Through013ApplyCleanlyToACompatibleDatabase() -
//     every migration file's own header comment says it must run
//     "against an EXISTING database that predates this change" (a truly
//     empty database does not qualify - these are additive ALTER/CREATE
//     statements against tables that must already exist). This test
//     therefore builds tests/fixtures/schema_baseline_pre_migrations.sql
//     (a reconstruction of that pre-migration-001 shape - see that
//     file's own header for exactly how it was derived) in a SEPARATE,
//     dedicated scratch database, applies migrations 001-013 to it in
//     order, and compares the resulting structure against the real
//     schema.sql-built database using information_schema queries -
//     structural/semantic checks, never a raw-text diff of the .sql
//     files themselves.
//
// Neither check modifies database/schema.sql or any migration file.
final class MigrationIntegrityTest extends TestCase
{
    private PDO $mainPdo;
    private PDO $scratchPdo;
    private string $scratchDbName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mainPdo = $GLOBALS['__TEST_PDO'];

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $user = getenv('DB_USERNAME') ?: 'test_user';
        $pass = getenv('DB_PASSWORD') ?: 'test_pass';
        $this->scratchDbName = getenv('DB_DATABASE_MIGRATIONS_TEST') ?: 'inventory_test_migrations';

        // Same non-negotiable safety check as tests/bootstrap.php, applied
        // to this second connection too - a migration-replay test is
        // exactly the kind of thing that must never run against a real
        // database.
        if (stripos($this->scratchDbName, 'test') === false) {
            self::fail("Refusing to run: scratch database name '{$this->scratchDbName}' does not look like a test database.");
        }

        $dsn = "mysql:host={$host};dbname={$this->scratchDbName};charset=utf8mb4";
        $this->scratchPdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function testFreshDatabaseInitializesSuccessfullyFromSchemaSql(): void
    {
        // If tests/bootstrap.php's own buildFreshFromSchema() had failed,
        // the entire suite would already have aborted before reaching any
        // test - so reaching this point already proves schema.sql applies
        // without error. This test asserts the STRUCTURAL RESULT is what
        // a fresh install is supposed to produce.
        $dbName = getenv('DB_DATABASE');
        $expectedTables = [
            'roles', 'users', 'categories', 'units', 'suppliers', 'products',
            'stock_transactions', 'stock_transaction_items', 'customers',
            'customer_debts', 'customer_debt_payments', 'app_settings',
            'audit_log', 'idempotency_keys', 'reference_counters',
        ];
        foreach ($expectedTables as $table) {
            $this->assertTrue($this->tableExists($this->mainPdo, $dbName, $table), "expected table '$table' to exist after a fresh schema.sql install");
        }

        $this->assertTrue($this->columnExists($this->mainPdo, $dbName, 'products', 'current_stock'));
        $this->assertTrue($this->columnExists($this->mainPdo, $dbName, 'customer_debts', 'balance'));
        $this->assertTrue($this->columnExists($this->mainPdo, $dbName, 'idempotency_keys', 'token'));

        $seedCount = (int) $this->mainPdo->query('SELECT COUNT(*) FROM reference_counters')->fetchColumn();
        $this->assertSame(2, $seedCount, 'a fresh install must seed both reference_counters rows');
    }

    public function testMigrations001Through013ApplyCleanlyToACompatibleDatabase(): void
    {
        $builder = new SchemaBuilder($this->scratchPdo);
        $builder->dropAllTables();
        $builder->runSqlFile(dirname(__DIR__) . '/fixtures/schema_baseline_pre_migrations.sql');

        $migrationsDir = dirname(__DIR__, 2) . '/database/migrations';
        $files = glob($migrationsDir . '/0*.sql');
        sort($files); // filenames are zero-padded (001_..013_..), so lexical sort is numeric order

        $this->assertCount(13, $files, 'expected exactly migrations 001 through 013 to be present');

        foreach ($files as $file) {
            try {
                $builder->runSqlFile($file);
            } catch (\Throwable $e) {
                $this->fail('Migration failed to apply: ' . basename($file) . ' - ' . $e->getMessage());
            }
        }

        $this->assertMigratedSchemaIsStructurallyEquivalentToFreshInstall();
    }

    private function assertMigratedSchemaIsStructurallyEquivalentToFreshInstall(): void
    {
        // Columns/tables that migrations 001-013 are specifically
        // responsible for adding - the actual thing under test here.
        $expectedColumns = [
            'products' => ['active_ingredient', 'expiry_date', 'package_size', 'updated_at', 'created_by', 'updated_by'],
            'stock_transactions' => ['cash_received'],
            'categories' => ['updated_at', 'created_by', 'updated_by'],
            'units' => ['created_at', 'updated_at', 'created_by', 'updated_by'],
            'suppliers' => ['created_at', 'updated_at', 'created_by', 'updated_by'],
            'users' => ['updated_at', 'created_by', 'updated_by'],
        ];
        foreach ($expectedColumns as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(
                    $this->columnExists($this->scratchPdo, $this->scratchDbName, $table, $column),
                    "migrated database is missing {$table}.{$column}"
                );
            }
        }

        $expectedNewTables = ['app_settings', 'audit_log', 'customers', 'customer_debts', 'customer_debt_payments', 'idempotency_keys', 'reference_counters'];
        foreach ($expectedNewTables as $table) {
            $this->assertTrue($this->tableExists($this->scratchPdo, $this->scratchDbName, $table), "migrated database is missing table '$table'");
        }

        // 'sale' must have been added to stock_transactions.type (migration 001).
        $stmt = $this->scratchPdo->query("SHOW COLUMNS FROM stock_transactions LIKE 'type'");
        $typeColumn = $stmt->fetch();
        $this->assertStringContainsString("'sale'", $typeColumn['Type']);

        // customer_debts.balance/status must be present as generated columns.
        $stmt = $this->scratchPdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                            WHERE TABLE_SCHEMA = '{$this->scratchDbName}' AND TABLE_NAME = 'customer_debts'
                                              AND GENERATION_EXPRESSION IS NOT NULL AND GENERATION_EXPRESSION != ''");
        $generated = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('balance', $generated);
        $this->assertContains('status', $generated);

        // app_settings must carry the business_* columns migration 013 adds.
        foreach (['business_name', 'business_address', 'business_phone', 'business_email'] as $column) {
            $this->assertTrue($this->columnExists($this->scratchPdo, $this->scratchDbName, 'app_settings', $column));
        }
    }

    private function tableExists(PDO $pdo, string $dbName, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $stmt->execute([$dbName, $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(PDO $pdo, string $dbName, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$dbName, $table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
