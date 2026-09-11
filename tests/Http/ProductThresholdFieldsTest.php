<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase L1 (Low Stock Alert / Reorder Management). Drives the real
// product/index.php page over real HTTP - see HttpServerTestCase for why.
// Covers the new reorder_quantity field and the hardened min_stock/
// reorder_quantity server-side validation (true non-negative integer,
// never a silent (int) truncation of a decimal/negative/non-numeric
// value). CSRF/RBAC themselves are already covered generically
// (CsrfTest.php/AuthorizationTest.php against stock-out/index.php as the
// representative page) - this file additionally proves the same gate on
// product/index.php's own POST handler, which (like
// stock-adjustment/index.php before K4-6-2) had no dedicated HTTP test
// of its own before this phase.
final class ProductThresholdFieldsTest extends HttpServerTestCase
{
    private array $cleanupProductIds = [];
    private array $cleanupUserIds = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupProductIds as $id) {
            $this->pdo->exec("DELETE FROM stock_transaction_items WHERE product_id = $id");
            $this->pdo->exec("DELETE FROM products WHERE id = $id");
        }
        foreach ($this->cleanupUserIds as $id) {
            $this->pdo->exec("DELETE FROM users WHERE id = $id");
        }
        parent::tearDown();
    }

    // ---- Valid submissions ----

    public function testCreatingAProductWithAReorderQuantitySavesIt(): void
    {
        $jar = $this->loggedInUserSession();
        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $sku = 'L1-' . bin2hex(random_bytes(4));

        $fields = $this->createFields($token, $sku);
        $fields['min_stock'] = '5';
        $fields['reorder_quantity'] = '20';
        $res = $this->httpPost($jar, '/product/index.php', $fields);
        $this->assertSame(302, $res['status'], 'create must succeed: ' . $res['body']);

        $product = $this->productBySku($sku);
        $this->assertSame(5, (int) $product['min_stock']);
        $this->assertSame(20, (int) $product['reorder_quantity']);
    }

    public function testCreatingAProductWithNoReorderQuantitySavesNull(): void
    {
        $jar = $this->loggedInUserSession();
        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $sku = 'L1-' . bin2hex(random_bytes(4));

        $fields = $this->createFields($token, $sku);
        $fields['reorder_quantity'] = '';
        $res = $this->httpPost($jar, '/product/index.php', $fields);
        $this->assertSame(302, $res['status'], 'create must succeed: ' . $res['body']);

        $product = $this->productBySku($sku);
        $this->assertNull($product['reorder_quantity']);
    }

    public function testReorderQuantityZeroIsAcceptedAndStored(): void
    {
        $jar = $this->loggedInUserSession();
        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $sku = 'L1-' . bin2hex(random_bytes(4));

        $fields = $this->createFields($token, $sku);
        $fields['reorder_quantity'] = '0';
        $res = $this->httpPost($jar, '/product/index.php', $fields);
        $this->assertSame(302, $res['status'], 'create must succeed: ' . $res['body']);

        $product = $this->productBySku($sku);
        $this->assertSame(0, (int) $product['reorder_quantity']);
        $this->assertNotNull($product['reorder_quantity']);
    }

    // ---- Rejections: min_stock ----

    public function testNegativeMinStockIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        $productId = $this->seedProduct();
        $before = $this->productById($productId);

        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $fields = $this->updateFields($token, $productId);
        $fields['min_stock'] = '-1';
        $res = $this->httpPost($jar, '/product/index.php', $fields);

        $this->assertSame(200, $res['status'], 'a negative min_stock must be rejected, not redirected');
        $after = $this->productById($productId);
        $this->assertSame($before['min_stock'], $after['min_stock'], 'min_stock must be unchanged');
    }

    public function testDecimalMinStockIsRejectedNotTruncated(): void
    {
        $jar = $this->loggedInUserSession();
        $productId = $this->seedProduct(min: 3);
        $before = $this->productById($productId);

        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $fields = $this->updateFields($token, $productId);
        $fields['min_stock'] = '5.7';
        $res = $this->httpPost($jar, '/product/index.php', $fields);

        $this->assertSame(200, $res['status'], 'a decimal min_stock must be rejected, not redirected');
        $after = $this->productById($productId);
        $this->assertSame($before['min_stock'], $after['min_stock'], 'min_stock must NOT have been truncated to 5 - must be completely unchanged');
    }

    public function testNonNumericMinStockIsRejected(): void
    {
        $jar = $this->loggedInUserSession();
        $productId = $this->seedProduct();
        $before = $this->productById($productId);

        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $fields = $this->updateFields($token, $productId);
        $fields['min_stock'] = 'abc';
        $res = $this->httpPost($jar, '/product/index.php', $fields);

        $this->assertSame(200, $res['status']);
        $after = $this->productById($productId);
        $this->assertSame($before['min_stock'], $after['min_stock']);
    }

    // ---- Rejections: reorder_quantity ----

    public function testNegativeReorderQuantityIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        $productId = $this->seedProduct();
        $before = $this->productById($productId);

        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $fields = $this->updateFields($token, $productId);
        $fields['reorder_quantity'] = '-5';
        $res = $this->httpPost($jar, '/product/index.php', $fields);

        $this->assertSame(200, $res['status'], 'a negative reorder_quantity must be rejected, not redirected');
        $after = $this->productById($productId);
        $this->assertSame($before['reorder_quantity'], $after['reorder_quantity']);
    }

    public function testDecimalReorderQuantityIsRejectedNotTruncated(): void
    {
        $jar = $this->loggedInUserSession();
        $productId = $this->seedProduct();
        $before = $this->productById($productId);

        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $fields = $this->updateFields($token, $productId);
        $fields['reorder_quantity'] = '12.5';
        $res = $this->httpPost($jar, '/product/index.php', $fields);

        $this->assertSame(200, $res['status'], 'a decimal reorder_quantity must be rejected, not redirected');
        $after = $this->productById($productId);
        $this->assertSame($before['reorder_quantity'], $after['reorder_quantity'], 'reorder_quantity must NOT have been truncated to 12 - must be completely unchanged');
    }

    public function testNonNumericReorderQuantityIsRejected(): void
    {
        $jar = $this->loggedInUserSession();
        $productId = $this->seedProduct();
        $before = $this->productById($productId);

        $form = $this->httpGet($jar, '/product/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $fields = $this->updateFields($token, $productId);
        $fields['reorder_quantity'] = 'xyz';
        $res = $this->httpPost($jar, '/product/index.php', $fields);

        $this->assertSame(200, $res['status']);
        $after = $this->productById($productId);
        $this->assertSame($before['reorder_quantity'], $after['reorder_quantity']);
    }

    // ---- RBAC ----

    public function testViewerCannotModifyMinStockOrReorderQuantity(): void
    {
        $viewerJar = $this->loggedInViewerSession();
        $productId = $this->seedProduct(min: 4);
        $before = $this->productById($productId);

        $form = $this->httpGet($viewerJar, '/product/index.php');
        $this->assertSame(200, $form['status'], 'a Viewer must still be able to view the page (read-only access)');
        $token = $this->extractCsrfToken($form['body']);
        $fields = $this->updateFields($token, $productId);
        $fields['min_stock'] = '99';
        $fields['reorder_quantity'] = '99';
        $this->httpPost($viewerJar, '/product/index.php', $fields);

        $after = $this->productById($productId);
        $this->assertSame($before['min_stock'], $after['min_stock'], 'a Viewer write attempt must not change min_stock');
        $this->assertSame($before['reorder_quantity'], $after['reorder_quantity'], 'a Viewer write attempt must not change reorder_quantity');
    }

    // ---- CSRF ----

    public function testMissingCsrfTokenOnProductUpdateIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        $productId = $this->seedProduct(min: 2);
        $before = $this->productById($productId);

        $this->httpGet($jar, '/product/index.php'); // establish a session
        $fields = $this->updateFields('', $productId);
        unset($fields['csrf_token']);
        $fields['min_stock'] = '77';
        $res = $this->httpPost($jar, '/product/index.php', $fields);

        $this->assertSame(403, $res['status'], 'a missing CSRF token must be rejected');
        $after = $this->productById($productId);
        $this->assertSame($before['min_stock'], $after['min_stock'], 'a CSRF-rejected request must perform no mutation');
    }

    // ---- helpers ----

    private function createFields(string $token, string $sku): array
    {
        return [
            'csrf_token' => $token,
            'action' => 'create',
            'name' => 'L1 Threshold Test Product',
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
            'reorder_quantity' => '',
            'note' => '',
        ];
    }

    private function updateFields(string $token, int $productId): array
    {
        $p = $this->productById($productId);
        return [
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
            'reorder_quantity' => $p['reorder_quantity'] !== null ? (string) $p['reorder_quantity'] : '',
            'note' => (string) $p['note'],
        ];
    }

    private function seedProduct(int $min = 5): int
    {
        $sku = 'L1-SEED-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock, min_stock, reorder_quantity) VALUES (?,?,?,?,0,?,NULL)');
        $stmt->execute(['L1 Seed Product', $sku, 1, 2, $min]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function productById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $this->assertNotFalse($row, "product $id not found");
        return $row;
    }

    private function productBySku(string $sku): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE sku = ?');
        $stmt->execute([$sku]);
        $row = $stmt->fetch();
        $this->assertNotFalse($row, "product with sku $sku not found");
        $this->cleanupProductIds[] = (int) $row['id'];
        return $row;
    }

    private function loggedInUserSession(): string
    {
        $email = 'l1.user.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['L1 Test User', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }

    private function loggedInViewerSession(): string
    {
        $email = 'l1.viewer.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,3)');
        $stmt->execute(['L1 Test Viewer', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }
}
