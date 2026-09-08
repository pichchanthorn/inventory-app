<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase K2b-1A (Product Track Batches toggle). Drives the real
// product/index.php page over real HTTP - see HttpServerTestCase for why
// this must be a real HTTP request rather than an in-process include.
// CSRF/RBAC themselves are already covered generically by CsrfTest.php/
// AuthorizationTest.php (the same csrf_verify()/canWrite() gate every
// write page shares) - this file only exercises the new track_batches
// field itself, on top of that already-proven gate.
final class ProductTrackBatchesTest extends HttpServerTestCase
{
    private array $cleanupProductIds = [];
    private array $cleanupUserIds = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupProductIds as $id) {
            $this->pdo->exec("DELETE FROM stock_transaction_items WHERE product_id = $id");
            // K4-5: opening-balance batches created by enabling tracking on
            // a product with existing stock - must be deleted before
            // products (product_batches.product_id has no ON DELETE
            // behavior, same as every other cleanup in this test suite).
            $this->pdo->exec("DELETE FROM product_batches WHERE product_id = $id");
            $this->pdo->exec("DELETE FROM products WHERE id = $id");
        }
        foreach ($this->cleanupUserIds as $id) {
            $this->pdo->exec("DELETE FROM users WHERE id = $id");
        }
        parent::tearDown();
    }

    public function testCreatingAProductWithTheCheckboxUncheckedPersistsTrackBatchesZero(): void
    {
        $jar = $this->loggedInUserSession();
        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $sku = 'K2B-' . bin2hex(random_bytes(4));

        $res = $this->httpPost($jar, '/product/index.php', $this->createFields($token, $sku, false));
        $this->assertSame(302, $res['status'], 'create must succeed: ' . $res['body']);

        $this->assertSame(0, $this->trackBatchesFor($sku));
    }

    public function testCreatingAProductWithTheCheckboxCheckedPersistsTrackBatchesOne(): void
    {
        $jar = $this->loggedInUserSession();
        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $sku = 'K2B-' . bin2hex(random_bytes(4));

        $res = $this->httpPost($jar, '/product/index.php', $this->createFields($token, $sku, true));
        $this->assertSame(302, $res['status'], 'create must succeed: ' . $res['body']);

        $this->assertSame(1, $this->trackBatchesFor($sku));
    }

    public function testEditingAProductFromUncheckedToCheckedTurnsTrackBatchesOn(): void
    {
        $jar = $this->loggedInUserSession();
        $productId = $this->seedProduct(0);

        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $res = $this->httpPost($jar, '/product/index.php', $this->updateFields($token, $productId, true));
        $this->assertSame(302, $res['status'], 'update must succeed: ' . $res['body']);

        $this->assertSame(1, $this->trackBatchesById($productId));
    }

    public function testEditingAProductFromCheckedToUncheckedTurnsTrackBatchesOff(): void
    {
        $jar = $this->loggedInUserSession();
        $productId = $this->seedProduct(1);

        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $res = $this->httpPost($jar, '/product/index.php', $this->updateFields($token, $productId, false));
        $this->assertSame(302, $res['status'], 'update must succeed: ' . $res['body']);

        $this->assertSame(0, $this->trackBatchesById($productId));
    }

    public function testEditingUnrelatedFieldsPreservesTheExistingTrackBatchesValue(): void
    {
        $jar = $this->loggedInUserSession();
        $productId = $this->seedProduct(1);

        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        // Re-submit the edit form with track_batches still checked (as a
        // real browser would - the checkbox's current state is part of
        // the same <form> as name/note/etc.) while changing an unrelated
        // field, and confirm track_batches survives untouched.
        $fields = $this->updateFields($token, $productId, true);
        $fields['note'] = 'unrelated edit';
        $res = $this->httpPost($jar, '/product/index.php', $fields);
        $this->assertSame(302, $res['status'], 'update must succeed: ' . $res['body']);

        $this->assertSame(1, $this->trackBatchesById($productId));
    }

    // ---- K4-5: enabling tracking on a product with existing stock ----
    //
    // testEditingAProductFromUncheckedToCheckedTurnsTrackBatchesOn() above
    // already proves the checkbox itself persists correctly - it seeds a
    // product with current_stock=0 (seedProduct() always does), so it
    // exercises none of K4-5's opening-balance logic at all. These two
    // tests are what actually drive that new behavior, through the real
    // page.

    public function testEnablingTrackBatchesOnAProductWithExistingStockCreatesOneOpeningBalanceBatch(): void
    {
        $jar = $this->loggedInUserSession();
        $productId = $this->seedProductWithStock(40);

        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $res = $this->httpPost($jar, '/product/index.php', $this->updateFields($token, $productId, true));
        $this->assertSame(302, $res['status'], 'update must succeed: ' . $res['body']);

        $this->assertSame(1, $this->trackBatchesById($productId));
        $this->assertSame(40, $this->currentStockById($productId), 'current_stock must be unchanged by enabling tracking');

        $batches = $this->batchesForProduct($productId);
        $this->assertCount(1, $batches, 'exactly one opening-balance batch must exist');
        $this->assertNull($batches[0]['batch_number']);
        $this->assertNull($batches[0]['expiry_date']);
        $this->assertSame(40, (int) $batches[0]['qty_on_hand']);
        $this->assertSame(0, (int) $batches[0]['qty_received']);
        $this->assertSame('opening_balance', $batches[0]['origin']);
        $this->assertNull($batches[0]['source_transaction_id']);
    }

    public function testResubmittingTheSameEnableRequestDoesNotCreateASecondOpeningBatch(): void
    {
        $jar = $this->loggedInUserSession();
        $productId = $this->seedProductWithStock(40);

        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $fields = $this->updateFields($token, $productId, true);

        $res1 = $this->httpPost($jar, '/product/index.php', $fields);
        $this->assertSame(302, $res1['status'], 'first submission must succeed: ' . $res1['body']);

        // Re-submit the exact same edit (same CSRF token is still valid -
        // CSRF tokens are session-scoped and reusable, unlike POS's
        // per-form idempotency token - see includes/csrf.php) - simulates
        // a duplicate/resubmitted edit of an already-tracked product.
        $res2 = $this->httpPost($jar, '/product/index.php', $fields);
        $this->assertSame(302, $res2['status'], 'second submission must also succeed (idempotent), not error: ' . $res2['body']);

        $this->assertSame(1, $this->trackBatchesById($productId));
        $this->assertSame(40, $this->currentStockById($productId));
        $this->assertCount(1, $this->batchesForProduct($productId), 'no second opening batch may be created');
    }

    private function seedProductWithStock(int $stock): int
    {
        $sku = 'K4-5-SEED-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock, track_batches) VALUES (?,?,?,?,?,0)');
        $stmt->execute(['K4-5 Seed Product', $sku, 1, 2, $stock]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function currentStockById(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    private function batchesForProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_batches WHERE product_id = ? ORDER BY id');
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    private function trackBatchesFor(string $sku): int
    {
        $stmt = $this->pdo->prepare('SELECT id, track_batches FROM products WHERE sku = ?');
        $stmt->execute([$sku]);
        $row = $stmt->fetch();
        $this->assertNotFalse($row, "product with sku $sku was not created");
        $this->cleanupProductIds[] = (int) $row['id'];
        return (int) $row['track_batches'];
    }

    private function trackBatchesById(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT track_batches FROM products WHERE id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    private function createFields(string $token, string $sku, bool $trackBatches): array
    {
        $fields = [
            'csrf_token' => $token,
            'action' => 'create',
            'name' => 'K2b Track Batches Test Product',
            'sku' => $sku,
            'barcode' => '',
            'package_size' => '',
            'category_id' => '',
            'supplier_id' => '',
            'unit_id' => '',
            'cost_price' => '1.00',
            'cost_price_currency' => 'USD',
            'sale_price' => '2.00',
            'sale_price_currency' => 'USD',
            'min_stock' => '0',
            'note' => '',
        ];
        if ($trackBatches) {
            $fields['track_batches'] = 'on';
        }
        return $fields;
    }

    private function updateFields(string $token, int $productId, bool $trackBatches): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $p = $stmt->fetch();

        $fields = [
            'csrf_token' => $token,
            'action' => 'update',
            'id' => (string) $productId,
            'name' => $p['name'],
            'sku' => $p['sku'],
            'barcode' => (string) $p['barcode'],
            'package_size' => (string) $p['package_size'],
            'category_id' => '',
            'supplier_id' => '',
            'unit_id' => '',
            'cost_price' => (string) $p['cost_price'],
            'cost_price_currency' => 'USD',
            'sale_price' => (string) $p['sale_price'],
            'sale_price_currency' => 'USD',
            'min_stock' => (string) $p['min_stock'],
            'note' => (string) $p['note'],
        ];
        if ($trackBatches) {
            $fields['track_batches'] = 'on';
        }
        return $fields;
    }

    private function seedProduct(int $trackBatches): int
    {
        $sku = 'K2B-SEED-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock, track_batches) VALUES (?,?,?,?,0,?)');
        $stmt->execute(['K2b Seed Product', $sku, 1, 2, $trackBatches]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function loggedInUserSession(): string
    {
        $email = 'k2b.product.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['K2b Product Test User', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }
}
