<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase P3-B2 (assisted Draft Purchase Order prefill). Drives the real
// purchase-order/create.php page over real HTTP with the Low Stock
// page's query-string handoff (?supplier_id=N&product_id[]=...).
//
// The prefill is rendered into the page's PREFILL_LINES JS constant,
// which addRow() then seeds the form from - so asserting on that
// constant asserts on exactly the data the browser will build rows
// from, without needing to execute JS. Every assertion below is about
// what the SERVER decided to emit; nothing here can be influenced by
// the client beyond the query string under test, which is the point.
final class PurchaseOrderPrefillHttpTest extends HttpServerTestCase
{
    private array $cleanupProductIds = [];
    private array $cleanupSupplierIds = [];
    private array $cleanupUserIds = [];
    private array $cleanupPoIds = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupPoIds as $id) {
            $this->pdo->exec("DELETE FROM audit_log WHERE entity_type = 'purchase_order' AND entity_id = $id");
            $this->pdo->exec("DELETE FROM purchase_order_items WHERE purchase_order_id = $id");
            $this->pdo->exec("DELETE FROM purchase_orders WHERE id = $id");
        }
        foreach ($this->cleanupProductIds as $id) {
            $this->pdo->exec("DELETE FROM products WHERE id = $id");
        }
        foreach ($this->cleanupSupplierIds as $id) {
            $this->pdo->exec("DELETE FROM suppliers WHERE id = $id");
        }
        foreach ($this->cleanupUserIds as $id) {
            $this->pdo->exec("DELETE FROM users WHERE id = $id");
        }
        parent::tearDown();
    }

    // ---- prefill happy path ----

    public function testPrefillsSuggestedLinesForTheSelectedSupplier(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $a = $this->seedProduct($supplierId, 20, '3.25');
        $b = $this->seedProduct($supplierId, 7, '4.00');

        $res = $this->httpGet($jar, "/purchase-order/create.php?supplier_id=$supplierId&product_id[]=$a&product_id[]=$b");
        $this->assertSame(200, $res['status']);

        $lines = $this->prefillLines($res['body']);
        $this->assertCount(2, $lines);
        $this->assertSame($a, $lines[0]['product_id']);
        $this->assertSame('20', $lines[0]['qty']);
        $this->assertSame('3.25', $lines[0]['cost']);
        $this->assertSame($b, $lines[1]['product_id']);
        $this->assertSame('7', $lines[1]['qty']);
        $this->assertSame('4.00', $lines[1]['cost']);
    }

    public function testSupplierIsPreselectedInTheForm(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct($supplierId, 5);

        $res = $this->httpGet($jar, "/purchase-order/create.php?supplier_id=$supplierId&product_id[]=$productId");
        $this->assertMatchesRegularExpression(
            '/<option value="' . $supplierId . '"\s+selected>/',
            $res['body'],
            'the handed-over supplier must render preselected'
        );
    }

    public function testQuantityIsBlankWhenReorderQuantityIsNullOrZero(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $nullQty = $this->seedProduct($supplierId, null);
        $zeroQty = $this->seedProduct($supplierId, 0);

        $res = $this->httpGet($jar, "/purchase-order/create.php?supplier_id=$supplierId&product_id[]=$nullQty&product_id[]=$zeroQty");

        $lines = $this->prefillLines($res['body']);
        $this->assertCount(2, $lines, 'both products are still suggested');
        $this->assertSame('', $lines[0]['qty'], 'NULL reorder_quantity must leave quantity blank');
        $this->assertSame('', $lines[1]['qty'], '0 reorder_quantity must leave quantity blank');
    }

    public function testCostIsRenderedAsAnEditableInputNotAFixedValue(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct($supplierId, 5, '9.99');

        $res = $this->httpGet($jar, "/purchase-order/create.php?supplier_id=$supplierId&product_id[]=$productId");
        $body = $res['body'];

        $lines = $this->prefillLines($body);
        $this->assertSame('9.99', $lines[0]['cost']);
        // The row template the prefill feeds is the same editable
        // unit_cost input the manual flow uses - not a readonly/disabled
        // field and not a hidden value.
        $this->assertStringContainsString('name="unit_cost[]" class="form-control price-amount-input"', $body);
        $this->assertStringNotContainsString('name="unit_cost[]" readonly', $body);
        $this->assertStringNotContainsString('name="unit_cost[]" disabled', $body);
    }

    // ---- validation / tampering ----

    public function testAProductBelongingToAnotherSupplierIsNeverSuggested(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierA = $this->seedSupplier();
        $supplierB = $this->seedSupplier();
        $ownedByA = $this->seedProduct($supplierA, 10);
        $ownedByB = $this->seedProduct($supplierB, 10);

        // Tampered URL: ask for supplier B's product under supplier A.
        $res = $this->httpGet($jar, "/purchase-order/create.php?supplier_id=$supplierA&product_id[]=$ownedByA&product_id[]=$ownedByB");
        $this->assertSame(200, $res['status'], 'a tampered id must not break the page');

        $lines = $this->prefillLines($res['body']);
        $this->assertCount(1, $lines, 'only the supplier-owned product may be suggested');
        $this->assertSame($ownedByA, $lines[0]['product_id']);
    }

    public function testAProductWithNoSupplierIsNeverSuggested(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $orphan = $this->seedProduct(null, 10);

        $res = $this->httpGet($jar, "/purchase-order/create.php?supplier_id=$supplierId&product_id[]=$orphan");
        $this->assertSame([], $this->prefillLines($res['body']));
    }

    public function testUnknownSupplierIsIgnoredAndThePageStillRenders(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct($supplierId, 10);

        $res = $this->httpGet($jar, "/purchase-order/create.php?supplier_id=999999999&product_id[]=$productId");
        $this->assertSame(200, $res['status']);
        $this->assertSame([], $this->prefillLines($res['body']), 'no supplier, no suggestions');
    }

    public function testNonNumericParametersAreIgnoredSafely(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct($supplierId, 10);

        $res = $this->httpGet($jar, "/purchase-order/create.php?supplier_id=abc&product_id[]=$productId");
        $this->assertSame(200, $res['status']);
        $this->assertSame([], $this->prefillLines($res['body']));

        $res = $this->httpGet($jar, "/purchase-order/create.php?supplier_id=$supplierId&product_id[]=notanid&product_id[]=$productId");
        $this->assertSame(200, $res['status']);
        $lines = $this->prefillLines($res['body']);
        $this->assertCount(1, $lines);
        $this->assertSame($productId, $lines[0]['product_id']);
    }

    public function testUnknownProductIdIsDropped(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct($supplierId, 10);

        $res = $this->httpGet($jar, "/purchase-order/create.php?supplier_id=$supplierId&product_id[]=999999999&product_id[]=$productId");
        $lines = $this->prefillLines($res['body']);
        $this->assertCount(1, $lines);
        $this->assertSame($productId, $lines[0]['product_id']);
    }

    public function testPrefillCreatesNoPurchaseOrderByItself(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct($supplierId, 10);

        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn();
        $res = $this->httpGet($jar, "/purchase-order/create.php?supplier_id=$supplierId&product_id[]=$productId");
        $after = (int) $this->pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn();

        $this->assertSame(200, $res['status']);
        $this->assertSame($before, $after, 'opening the prefilled form must never create a purchase order');
    }

    // ---- authorization ----

    public function testViewerCannotReachThePrefilledCreatePage(): void
    {
        $jar = $this->loggedInViewerSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct($supplierId, 10);

        $res = $this->httpGet($jar, "/purchase-order/create.php?supplier_id=$supplierId&product_id[]=$productId");
        $this->assertStringContainsString(
            '/purchase-order/index.php',
            $res['headers'],
            'a Viewer must still be redirected away from the create page, prefill or not'
        );
        $this->assertSame([], $this->prefillLines($res['body']), 'a Viewer must not be handed prefill data');
    }

    // ---- the prefilled form still submits through the unchanged path ----

    public function testAPrefilledFormStillCreatesThePurchaseOrderNormally(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct($supplierId, 12, '2.50');

        $getRes = $this->httpGet($jar, "/purchase-order/create.php?supplier_id=$supplierId&product_id[]=$productId");
        $token = $this->extractCsrfToken($getRes['body']);
        $idempotencyToken = $this->extractIdempotencyToken($getRes['body']);
        $lines = $this->prefillLines($getRes['body']);
        $this->assertCount(1, $lines);

        // Submit exactly what the prefilled form would have posted, but
        // with a user-edited cost - proving the cost is not pinned.
        $res = $this->httpPost($jar, '/purchase-order/create.php', [
            'csrf_token' => $token,
            'idempotency_token' => $idempotencyToken,
            'supplier_id' => (string) $supplierId,
            'order_date' => date('Y-m-d'),
            'expected_date' => '',
            'note' => '',
            'product_id' => [(string) $lines[0]['product_id']],
            'ordered_qty' => [$lines[0]['qty']],
            'unit_cost' => ['5.75'],
            'unit_cost_currency' => ['USD'],
        ]);

        preg_match('/Location:.*?id=(\d+)/', $res['headers'], $m);
        $this->assertNotEmpty($m, 'a prefilled form must submit through the normal path: ' . $res['body']);
        $poId = (int) $m[1];
        $this->cleanupPoIds[] = $poId;

        $stmt = $this->pdo->prepare('SELECT ordered_qty, unit_cost FROM purchase_order_items WHERE purchase_order_id = ?');
        $stmt->execute([$poId]);
        $item = $stmt->fetch();
        $this->assertSame(12, (int) $item['ordered_qty'], 'the prefilled quantity must be what gets ordered');
        $this->assertSame('5.75', (string) $item['unit_cost'], 'the user-edited cost must win over the prefilled default');
    }

    public function testCreatePageWithoutPrefillParamsIsUnchanged(): void
    {
        $jar = $this->loggedInUserSession();

        $res = $this->httpGet($jar, '/purchase-order/create.php');
        $this->assertSame(200, $res['status']);
        $this->assertSame([], $this->prefillLines($res['body']), 'a normal visit must carry no prefill');
        $this->assertStringContainsString('name="product_id[]"', $res['body'], 'the ordinary blank row must still render');
    }

    // ---- helpers ----

    // Reads the server-emitted PREFILL_LINES JS constant - i.e. exactly
    // the data the page will build its rows from.
    private function prefillLines(string $html): array
    {
        if (!preg_match('/const PREFILL_LINES = (.*?);\n/', $html, $m)) {
            return [];
        }
        $decoded = json_decode($m[1], true);
        return is_array($decoded) ? $decoded : [];
    }

    private function extractIdempotencyToken(string $html): string
    {
        preg_match('/name="idempotency_token" value="([a-f0-9]+)"/', $html, $m);
        return $m[1] ?? '';
    }

    private function seedSupplier(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO suppliers (name) VALUES (?)');
        $stmt->execute(['P3B2 Supplier ' . bin2hex(random_bytes(4))]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupSupplierIds[] = $id;
        return $id;
    }

    private function seedProduct(?int $supplierId, ?int $reorderQuantity, string $costPrice = '1.00'): int
    {
        $sku = 'P3B2-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, sku, cost_price, sale_price, current_stock, min_stock, reorder_quantity, supplier_id)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmt->execute(['P3B2 Product ' . bin2hex(random_bytes(3)), $sku, $costPrice, 2, 0, 5, $reorderQuantity, $supplierId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function loggedInUserSession(): string
    {
        $email = 'p3b2.user.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['P3B2 Test User', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }

    private function loggedInViewerSession(): string
    {
        $email = 'p3b2.viewer.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,3)');
        $stmt->execute(['P3B2 Test Viewer', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }
}
