<?php
declare(strict_types=1);

namespace Tests\Backup;

use PDO;
use PHPUnit\Framework\TestCase;

// J3 - Backup & Restore Verification.
//
// Proves: REAL BACKUP -> CLEAN DATABASE -> REAL RESTORE -> REAL DATA
// VERIFICATION -> APPLICATION READABILITY, using the actual, unmodified
// production backup function (includes/backup.php::streamDatabaseBackup())
// and a real `mysql` CLI restore against a genuinely separate, disposable
// scratch database - never inventory_db, never the shared inventory_test
// database used as the restore TARGET (inventory_test is only ever the
// SOURCE the backup is taken from here).
//
// Deliberately does NOT extend Tests\TestCase (no transaction wrap) - the
// same reasoning as tests/Concurrency/* and tests/Http/*: this needs
// genuinely committed rows visible to a second connection (the mysql CLI
// process) and a second database entirely. Seed data is committed
// directly and removed again in tearDown().
final class BackupRestoreTest extends TestCase
{
    private PDO $sourcePdo;
    private string $sourceDbName;
    private string $scratchDbName;
    private PDO $scratchPdo;
    private array $cleanupProductIds = [];
    private array $cleanupUserIds = [];
    private array $cleanupCustomerIds = [];
    private array $cleanupCategoryIds = [];
    private array $cleanupUnitIds = [];
    private array $cleanupSupplierIds = [];
    private ?string $dumpFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourcePdo = $GLOBALS['__TEST_PDO'];
        $this->sourceDbName = (string) getenv('DB_DATABASE');

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $user = getenv('DB_USERNAME') ?: 'test_user';
        $pass = getenv('DB_PASSWORD') ?: 'test_pass';
        $this->scratchDbName = getenv('DB_DATABASE_BACKUP_RESTORE_TEST') ?: 'inventory_test_backup_restore';

        // Same non-negotiable safety check as tests/bootstrap.php and
        // tests/Schema/MigrationIntegrityTest.php - a backup/restore test
        // is exactly the kind of thing that must never be able to target
        // a real database, by construction, not by convention.
        if (stripos($this->sourceDbName, 'test') === false || stripos($this->scratchDbName, 'test') === false) {
            self::fail("Refusing to run: source '{$this->sourceDbName}' or scratch '{$this->scratchDbName}' database name does not look like a test database.");
        }

        $dsn = "mysql:host={$host};dbname={$this->scratchDbName};charset=utf8mb4";
        $this->scratchPdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->dumpFile !== null && file_exists($this->dumpFile)) {
            @unlink($this->dumpFile);
        }
        foreach ($this->cleanupCustomerIds as $id) {
            $this->sourcePdo->exec("DELETE cdp FROM customer_debt_payments cdp JOIN customer_debts cd ON cd.id = cdp.debt_id WHERE cd.customer_id = $id");
            $this->sourcePdo->exec("DELETE FROM customer_debts WHERE customer_id = $id");
            $this->sourcePdo->exec("DELETE FROM customers WHERE id = $id");
        }
        foreach ($this->cleanupProductIds as $id) {
            $this->sourcePdo->exec("DELETE FROM audit_log WHERE entity_type = 'product' AND entity_id = $id");
            $this->sourcePdo->exec("DELETE FROM stock_transaction_items WHERE product_id = $id");
            $this->sourcePdo->exec("DELETE FROM products WHERE id = $id");
        }
        foreach ($this->cleanupUserIds as $id) {
            $this->sourcePdo->exec("DELETE FROM users WHERE id = $id");
        }
        foreach ($this->cleanupSupplierIds as $id) {
            $this->sourcePdo->exec("DELETE FROM suppliers WHERE id = $id");
        }
        foreach ($this->cleanupCategoryIds as $id) {
            $this->sourcePdo->exec("DELETE FROM categories WHERE id = $id");
        }
        foreach ($this->cleanupUnitIds as $id) {
            $this->sourcePdo->exec("DELETE FROM units WHERE id = $id");
        }
        parent::tearDown();
    }

    // ================================================
    // A. Backup verification (dump text only - no restore needed)
    // ================================================

    public function testBackupProducesACompleteNonEmptyDumpOfTheRealProductionFunction(): void
    {
        $data = $this->seedRepresentativeDataset();
        $dump = $this->captureRealBackup();

        $this->assertNotSame('', trim($dump), 'the dump must not be empty');

        // Structural completeness - the real production function loops
        // SHOW TABLES, so every table that exists must appear.
        $expectedTables = [
            'roles', 'users', 'categories', 'units', 'suppliers', 'products',
            'stock_transactions', 'stock_transaction_items', 'customers',
            'customer_debts', 'customer_debt_payments', 'app_settings',
            'audit_log', 'idempotency_keys', 'reference_counters',
        ];
        foreach ($expectedTables as $table) {
            $this->assertStringContainsString("DROP TABLE IF EXISTS `$table`", $dump, "missing DROP for table $table");
            $this->assertStringContainsString("CREATE TABLE `$table`", $dump, "missing CREATE TABLE for table $table");
        }

        // INSERT statements exist for tables that have data.
        $this->assertStringContainsString('INSERT INTO `products`', $dump);
        $this->assertStringContainsString('INSERT INTO `customer_debts`', $dump);
        $this->assertStringContainsString('INSERT INTO `app_settings`', $dump);

        // utf8mb4 configuration present (Khmer-safety requirement).
        $this->assertStringContainsString('SET NAMES utf8mb4;', $dump);

        // Generated columns (customer_debts.balance/status) must never be
        // explicitly INSERTed - MySQL computes and rejects them. Check the
        // actual INSERT INTO `customer_debts` (...) column list, not just
        // absence anywhere in the file (the word "status"/"balance" could
        // legitimately appear elsewhere, e.g. inside a CREATE TABLE).
        $this->assertMatchesRegularExpression(
            '/INSERT INTO `customer_debts` \(([^)]*)\)/',
            $dump,
            'expected at least one customer_debts INSERT statement'
        );
        preg_match('/INSERT INTO `customer_debts` \(([^)]*)\)/', $dump, $m);
        $columnList = $m[1];
        $this->assertStringNotContainsString('`balance`', $columnList, 'generated column balance must not be inserted');
        $this->assertStringNotContainsString('`status`', $columnList, 'generated column status must not be inserted');

        $this->assertProductSeedPresentInDump($dump, $data);
    }

    private function assertProductSeedPresentInDump(string $dump, array $data): void
    {
        // The Khmer product name must survive byte-for-byte into the raw
        // dump text (not just "some INSERT exists").
        $this->assertStringContainsString($data['product1Name'], $dump, 'Khmer product name must appear verbatim in the dump');
    }

    // ================================================
    // B-D. Full pipeline: REAL BACKUP -> CLEAN DATABASE -> REAL RESTORE
    // -> REAL DATA VERIFICATION -> APPLICATION READABILITY
    // ================================================

    public function testFullBackupRestoreCycleAndPostRestoreVerification(): void
    {
        $data = $this->seedRepresentativeDataset();
        $dump = $this->captureRealBackup();

        // ---- B. Restore: a completely separate, disposable database ----
        $this->resetScratchDatabase();
        $this->dumpFile = tempnam(sys_get_temp_dir(), 'j3dump') . '.sql';
        file_put_contents($this->dumpFile, $dump);

        $this->restoreDumpViaRealMysqlClient($this->dumpFile);

        // Reconnect fresh after the CLI restore, to read genuinely
        // committed state rather than anything cached on the setUp()
        // connection.
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $userEnv = getenv('DB_USERNAME') ?: 'test_user';
        $passEnv = getenv('DB_PASSWORD') ?: 'test_pass';
        $restored = new PDO(
            "mysql:host={$host};dbname={$this->scratchDbName};charset=utf8mb4",
            $userEnv,
            $passEnv,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // ---- C/D. Business-data verification ----

        // 1-4: categories/units/suppliers/products
        $stmt = $restored->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$data['product1Id']]);
        $p1 = $stmt->fetch();
        $this->assertNotFalse($p1, 'product 1 must survive restore');
        $this->assertSame($data['product1Name'], $p1['name'], 'product name (Khmer) must survive restore exactly');
        $this->assertSame('50kg', $p1['package_size']);
        $this->assertSame('NPK 15-15-15', $p1['active_ingredient']);
        $this->assertEqualsWithDelta(12.34, (float) $p1['cost_price'], 0.001, 'decimal precision must survive restore');
        $this->assertEqualsWithDelta(18.99, (float) $p1['sale_price'], 0.001);

        $stmt = $restored->prepare('SELECT name FROM categories WHERE id = ?');
        $stmt->execute([$data['categoryId']]);
        $this->assertSame($data['categoryName'], $stmt->fetchColumn());

        $stmt = $restored->prepare('SELECT name FROM units WHERE id = ?');
        $stmt->execute([$data['unitId']]);
        $this->assertSame($data['unitName'], $stmt->fetchColumn());

        $stmt = $restored->prepare('SELECT name FROM suppliers WHERE id = ?');
        $stmt->execute([$data['supplierId']]);
        $this->assertSame($data['supplierName'], $stmt->fetchColumn(), 'supplier name (Khmer) must survive restore');

        // 5-6: stock transactions / stock quantities
        $stmt = $restored->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$data['product1Id']]);
        $this->assertSame($data['product1ExpectedStockAfterSale'], (int) $stmt->fetchColumn(), 'restored current_stock must reflect every stock movement made before backup');

        $stmt = $restored->prepare("SELECT COUNT(*) FROM stock_transactions WHERE reference = ?");
        $stmt->execute([$data['stockInReference']]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'Stock In transaction must survive restore');

        // 7-9: sale record, sale total, cash_received
        $stmt = $restored->prepare("SELECT t.cash_received, SUM(i.subtotal) AS total
                                     FROM stock_transactions t
                                     JOIN stock_transaction_items i ON i.transaction_id = t.id
                                     WHERE t.reference = ? GROUP BY t.id");
        $stmt->execute([$data['saleReference']]);
        $sale = $stmt->fetch();
        $this->assertNotFalse($sale, 'the cash sale must survive restore');
        $this->assertEqualsWithDelta(189.90, (float) $sale['total'], 0.001, 'sale total (money) must survive restore exactly');
        $this->assertEqualsWithDelta(200.00, (float) $sale['cash_received'], 0.001, 'cash_received must survive restore exactly');

        // 10-14: customer, debt, payment, balance, status
        $stmt = $restored->prepare('SELECT name FROM customers WHERE id = ?');
        $stmt->execute([$data['customerId']]);
        $this->assertSame($data['customerName'], $stmt->fetchColumn(), 'customer name (Khmer) must survive restore');

        $stmt = $restored->prepare('SELECT total_amount, paid_amount, balance, status FROM customer_debts WHERE id = ?');
        $stmt->execute([$data['debtId']]);
        $debt = $stmt->fetch();
        $this->assertNotFalse($debt, 'the debt must survive restore');
        $this->assertEqualsWithDelta(42.50, (float) $debt['total_amount'], 0.001, 'debt total (money) must survive restore exactly');
        $this->assertEqualsWithDelta(15.25, (float) $debt['paid_amount'], 0.001, 'debt payment amount (money) must survive restore exactly');
        // balance/status are GENERATED columns - restored MySQL/MariaDB
        // recomputes them itself (they are never in the dump's INSERT),
        // proving the generated-column definition itself survived the
        // DROP+CREATE TABLE in the restore, not just the base values.
        $this->assertEqualsWithDelta(27.25, (float) $debt['balance'], 0.001, 'generated column balance must recompute correctly post-restore');
        $this->assertSame('partially_paid', $debt['status'], 'generated column status must recompute correctly post-restore');

        // 15-17: exchange rate, business information, invoice settings
        $stmt = $restored->query('SELECT * FROM app_settings WHERE id = 1');
        $settings = $stmt->fetch();
        $this->assertNotFalse($settings, 'app_settings must survive restore');
        $this->assertEqualsWithDelta(4100.00, (float) $settings['usd_to_khr_rate'], 0.001, 'exchange rate (money) must survive restore exactly');
        $this->assertSame($data['businessName'], $settings['business_name'], 'business name (Khmer) must survive restore');
        $this->assertSame($data['businessAddress'], $settings['business_address'], 'business address (Khmer) must survive restore');
        $this->assertSame('0973100485', $settings['business_phone']);

        // 18: audit records
        $stmt = $restored->prepare("SELECT action, entity_type, entity_id, after_snapshot FROM audit_log WHERE entity_type = 'product' AND entity_id = ?");
        $stmt->execute([$data['product1Id']]);
        $audit = $stmt->fetch();
        $this->assertNotFalse($audit, 'the audit record must survive restore');
        $this->assertSame('create', $audit['action']);
        $snapshot = json_decode($audit['after_snapshot'], true);
        $this->assertSame($data['product1Name'], $snapshot['name'], 'audit JSON snapshot must preserve Khmer text exactly');

        // 19: user roles / RBAC
        $stmt = $restored->prepare('SELECT role_id FROM users WHERE id = ?');
        $stmt->execute([$data['adminId']]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'Admin role_id must survive restore');
        $stmt->execute([$data['staffId']]);
        $this->assertSame(2, (int) $stmt->fetchColumn(), 'User role_id must survive restore');

        // 20: reference counter state
        $stmt = $restored->prepare("SELECT next_value FROM reference_counters WHERE counter_key = 'stock_transactions'");
        $stmt->execute();
        $this->assertSame($data['refCounterStockAfter'], (int) $stmt->fetchColumn(), 'reference_counters state must survive restore exactly (no reset)');
        $stmt = $restored->prepare("SELECT next_value FROM reference_counters WHERE counter_key = 'customer_debts'");
        $stmt->execute();
        $this->assertSame($data['refCounterDebtAfter'], (int) $stmt->fetchColumn());

        // 23: foreign-key relationships (join across restored tables)
        $stmt = $restored->prepare('SELECT p.name FROM stock_transaction_items i
                                     JOIN products p ON p.id = i.product_id
                                     JOIN stock_transactions t ON t.id = i.transaction_id
                                     WHERE t.reference = ?');
        $stmt->execute([$data['saleReference']]);
        $this->assertSame($data['product1Name'], $stmt->fetchColumn(), 'FK relationship stock_transaction_items -> products must remain valid post-restore');

        $stmt = $restored->prepare('SELECT c.name FROM customer_debts d JOIN customers c ON c.id = d.customer_id WHERE d.id = ?');
        $stmt->execute([$data['debtId']]);
        $this->assertSame($data['customerName'], $stmt->fetchColumn(), 'FK relationship customer_debts -> customers must remain valid post-restore');

        // 24: constraints - actually enforced, not just structurally
        // present. Attempt a raw UPDATE that would drive current_stock
        // negative; the restored CHECK constraint must reject it.
        $this->expectRestoredCheckConstraintToReject($restored, $data['product1Id']);

        // 25: AUTO_INCREMENT continuity - a newly inserted row must get an
        // id greater than the restored data's own max id, proving the
        // counter carried over rather than resetting to 1.
        $maxIdBefore = (int) $restored->query('SELECT MAX(id) FROM products')->fetchColumn();
        $stmt = $restored->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock) VALUES (?,?,?,?,?)');
        $stmt->execute(['J3BR post-restore product', 'J3BR-POSTRESTORE-' . bin2hex(random_bytes(3)), 1, 1, 0]);
        $newId = (int) $restored->lastInsertId();
        $this->assertGreaterThan($maxIdBefore, $newId, 'AUTO_INCREMENT must continue from the restored state, not reset to 1');

        // ---- Application readability: real production functions against
        // the restored connection. Note: literally re-invoking
        // `vendor/bin/phpunit` pointed at this restored database was
        // considered and rejected - tests/bootstrap.php unconditionally
        // rebuilds the schema from scratch on every run
        // (SchemaBuilder::buildFreshFromSchema()), which would destroy
        // the very restored data this test exists to verify, before a
        // single assertion could run. Calling the same real,
        // unmodified production functions J1's own tests exercise
        // (recordStockOut, adjustStock) directly against the restored
        // connection is the closest non-destructive equivalent: it
        // proves the application's actual code paths work correctly
        // against restored data, without wiping it first. ----
        require_once dirname(__DIR__, 2) . '/includes/stock.php';
        $reference = recordStockOut($restored, [['product_id' => $data['product1Id'], 'qty' => 5, 'price' => 1]], date('Y-m-d'), 'J3BR post-restore app-readability check', $data['adminId']);
        $this->assertStringStartsWith('STO-', $reference, 'a real production function must run successfully against the restored database');

        $stmt = $restored->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$data['product1Id']]);
        $this->assertSame($data['product1ExpectedStockAfterSale'] - 5, (int) $stmt->fetchColumn(), 'the restored data must be the correct baseline for further real application writes');
    }

    private function expectRestoredCheckConstraintToReject(PDO $restored, int $productId): void
    {
        try {
            $stmt = $restored->prepare('UPDATE products SET current_stock = -1 WHERE id = ?');
            $stmt->execute([$productId]);
            $this->fail('Expected the restored chk_products_current_stock_nonneg CHECK constraint to reject a negative current_stock.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('chk_products_current_stock_nonneg', $e->getMessage(), 'the restored CHECK constraint must be the one actually enforcing this, not a coincidental different failure');
        }
    }

    // ================================================
    // Helpers
    // ================================================

    private function captureRealBackup(): string
    {
        require_once dirname(__DIR__, 2) . '/includes/backup.php';

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $userEnv = getenv('DB_USERNAME') ?: 'test_user';
        $passEnv = getenv('DB_PASSWORD') ?: 'test_pass';
        $dsn = "mysql:host={$host};dbname={$this->sourceDbName};charset=utf8mb4";

        ob_start();
        streamDatabaseBackup($this->sourcePdo, $dsn, $userEnv, $passEnv);
        return ob_get_clean();
    }

    private function resetScratchDatabase(): void
    {
        $tables = $this->scratchPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if ($tables) {
            $this->scratchPdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $table) {
                $this->scratchPdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
            }
            $this->scratchPdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    // Restores the dump via the REAL mysql CLI client - the exact
    // mechanism includes/backup.php's own generated comment recommends
    // ("mysql -u USER -p DBNAME < this_file.sql") - executing real SQL
    // through a real SQL parser, not a naive PHP statement splitter that
    // could be tripped up by a semicolon inside a quoted data value (a
    // business address, a note field, a JSON audit snapshot). The target
    // database name is taken only from the safety-checked scratch-DB
    // property - never from any other source - so this can only ever
    // write to the disposable test database.
    private function restoreDumpViaRealMysqlClient(string $dumpFile): void
    {
        $mysqlBinary = trim((string) shell_exec('which mysql'));
        if ($mysqlBinary === '') {
            self::fail('The `mysql` CLI client is not available in this environment - cannot perform a real restore. STOPPING rather than weakening this test with a fake/parsed restore.');
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $userEnv = getenv('DB_USERNAME') ?: 'test_user';
        $passEnv = getenv('DB_PASSWORD') ?: 'test_pass';

        $descriptors = [
            0 => ['file', $dumpFile, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = [$mysqlBinary, '-h', $host, '-u', $userEnv, '-p' . $passEnv, $this->scratchDbName];
        $proc = proc_open($cmd, $descriptors, $pipes);
        $this->assertIsResource($proc, 'failed to launch the mysql CLI restore process');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $this->assertSame(0, $exitCode, "real mysql CLI restore into '{$this->scratchDbName}' failed (exit $exitCode). stderr: $stderr / stdout: $stdout");
    }

    private function seedRepresentativeDataset(): array
    {
        $pdo = $this->sourcePdo;
        $marker = 'J3BR-' . bin2hex(random_bytes(3));

        $categoryName = "J3BR Fertilizers $marker";
        $stmt = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)');
        $stmt->execute([$categoryName, 'j3br-fertilizers-' . strtolower($marker)]);
        $categoryId = (int) $pdo->lastInsertId();
        $this->cleanupCategoryIds[] = $categoryId;

        $unitName = "J3BR Bag $marker";
        $stmt = $pdo->prepare('INSERT INTO units (name) VALUES (?)');
        $stmt->execute([$unitName]);
        $unitId = (int) $pdo->lastInsertId();
        $this->cleanupUnitIds[] = $unitId;

        // Khmer text deliberately included in the supplier name.
        $supplierName = "ក្រុមហ៊ុន J3BR Agro Supply $marker";
        $stmt = $pdo->prepare('INSERT INTO suppliers (name, phone) VALUES (?, ?)');
        $stmt->execute([$supplierName, '012345678']);
        $supplierId = (int) $pdo->lastInsertId();
        $this->cleanupSupplierIds[] = $supplierId;

        $admin = testSeedAdmin($pdo);
        $staff = testSeedUserRole($pdo);
        $this->cleanupUserIds[] = $admin['id'];
        $this->cleanupUserIds[] = $staff['id'];

        // Product 1: agrochemical, Khmer name, package info, decimal money.
        $product1Name = "ជី NPK $marker";
        $stmt = $pdo->prepare('INSERT INTO products (name, sku, category_id, supplier_id, unit_id, package_size, active_ingredient, expiry_date, cost_price, sale_price, current_stock, created_by, updated_by)
                                VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?)');
        $stmt->execute([$product1Name, "SKU-$marker-1", $categoryId, $supplierId, $unitId, '50kg', 'NPK 15-15-15', date('Y-m-d', strtotime('+1 year')), 12.34, 18.99, $admin['id'], $admin['id']]);
        $product1Id = (int) $pdo->lastInsertId();
        $this->cleanupProductIds[] = $product1Id;

        // A realistic audit_log row, exactly as product/index.php would
        // write on a real product create.
        logAudit($pdo, $admin['id'], 'create', 'product', $product1Id, null, [
            'id' => $product1Id, 'name' => $product1Name, 'sku' => "SKU-$marker-1",
            'cost_price' => 12.34, 'sale_price' => 18.99,
        ]);

        // Product 2: plain, used for the credit sale.
        $stmt = $pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock, created_by, updated_by) VALUES (?,?,?,?,0,?,?)');
        $stmt->execute(["J3BR Plain Product $marker", "SKU-$marker-2", 5.00, 8.50, $admin['id'], $admin['id']]);
        $product2Id = (int) $pdo->lastInsertId();
        $this->cleanupProductIds[] = $product2Id;

        $stmt = $pdo->prepare("SELECT next_value FROM reference_counters WHERE counter_key = 'stock_transactions'");
        $stmt->execute();
        $refCounterStockBefore = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT next_value FROM reference_counters WHERE counter_key = 'customer_debts'");
        $stmt->execute();
        $refCounterDebtBefore = (int) $stmt->fetchColumn();

        // Stock In (real production function): 100 x product1, 50 x product2.
        $stockInReference = recordStockIn(
            $pdo,
            [
                ['product_id' => $product1Id, 'qty' => 100, 'cost' => 12.34],
                ['product_id' => $product2Id, 'qty' => 50, 'cost' => 5.00],
            ],
            date('Y-m-d'), $supplierId, "J3BR stock in $marker", $admin['id']
        );

        // Cash sale (real production function): 10 x product1 @ 18.99 = 189.90, cash 200.00.
        $saleReference = recordStockOut(
            $pdo,
            [['product_id' => $product1Id, 'qty' => 10, 'price' => 18.99]],
            date('Y-m-d'), '', $staff['id'], 'sale', 200.00
        );
        $product1ExpectedStockAfterSale = 100 - 10;

        // Customer + credit sale + partial payment (real production functions).
        $customerName = "អតិថិជន ស្រូវ $marker";
        $stmt = $pdo->prepare('INSERT INTO customers (name, phone) VALUES (?, ?)');
        $stmt->execute([$customerName, '098765432']);
        $customerId = (int) $pdo->lastInsertId();
        $this->cleanupCustomerIds[] = $customerId;

        $creditResult = recordCreditSale(
            $pdo,
            [['product_id' => $product2Id, 'qty' => 5, 'price' => 8.50]],
            date('Y-m-d'), $staff['id'], $customerId, null, null, date('Y-m-d', strtotime('+30 days'))
        );
        $stmt = $pdo->prepare('SELECT id FROM customer_debts WHERE reference = ?');
        $stmt->execute([$creditResult['debt_reference']]);
        $debtId = (int) $stmt->fetchColumn();

        recordDebtPayment($pdo, $debtId, 15.25, date('Y-m-d'), "J3BR partial payment $marker", $staff['id']);

        // Business/invoice settings (Khmer business identity).
        $businessName = "ហាងជី PCTN $marker";
        $businessAddress = "ភ្នំពេញ, កម្ពុជា $marker";
        $stmt = $pdo->prepare('INSERT INTO app_settings (id, usd_to_khr_rate, business_name, business_address, business_phone, business_email)
                                VALUES (1, 4100.00, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE usd_to_khr_rate = VALUES(usd_to_khr_rate),
                                    business_name = VALUES(business_name), business_address = VALUES(business_address),
                                    business_phone = VALUES(business_phone), business_email = VALUES(business_email)');
        $stmt->execute([$businessName, $businessAddress, '0973100485', 'j3br-test@example.com']);

        $stmt = $pdo->prepare("SELECT next_value FROM reference_counters WHERE counter_key = 'stock_transactions'");
        $stmt->execute();
        $refCounterStockAfter = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT next_value FROM reference_counters WHERE counter_key = 'customer_debts'");
        $stmt->execute();
        $refCounterDebtAfter = (int) $stmt->fetchColumn();

        return [
            'categoryId' => $categoryId, 'categoryName' => $categoryName,
            'unitId' => $unitId, 'unitName' => $unitName,
            'supplierId' => $supplierId, 'supplierName' => $supplierName,
            'adminId' => $admin['id'], 'staffId' => $staff['id'],
            'product1Id' => $product1Id, 'product1Name' => $product1Name,
            'product2Id' => $product2Id,
            'stockInReference' => $stockInReference,
            'saleReference' => $saleReference,
            'product1ExpectedStockAfterSale' => $product1ExpectedStockAfterSale,
            'customerId' => $customerId, 'customerName' => $customerName,
            'debtId' => $debtId,
            'businessName' => $businessName, 'businessAddress' => $businessAddress,
            'refCounterStockAfter' => $refCounterStockAfter,
            'refCounterDebtAfter' => $refCounterDebtAfter,
            'refCounterStockBefore' => $refCounterStockBefore,
            'refCounterDebtBefore' => $refCounterDebtBefore,
        ];
    }
}
