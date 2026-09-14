<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase P2 (Purchase Order Receiving / Stock In Integration).
// Drives the real purchase-order/{index,receive,view}.php pages over real
// HTTP - see HttpServerTestCase for why a real HTTP request (rather than
// an in-process include) is used throughout this suite: csrf_verify()/
// auth_check.php call exit()/header() directly.
final class PurchaseOrderReceivingHttpTest extends HttpServerTestCase
{
    private array $cleanupPoIds = [];
    private array $cleanupProductIds = [];
    private array $cleanupSupplierIds = [];
    private array $cleanupUserIds = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupPoIds as $id) {
            $this->pdo->exec("DELETE FROM purchase_order_receipts WHERE purchase_order_item_id IN (SELECT id FROM purchase_order_items WHERE purchase_order_id = $id)");
            $this->pdo->exec("DELETE FROM audit_log WHERE entity_type = 'purchase_order' AND entity_id = $id");
            $this->pdo->exec("DELETE FROM purchase_orders WHERE id = $id");
        }
        foreach ($this->cleanupProductIds as $id) {
            $this->pdo->exec("DELETE FROM stock_transaction_item_batches WHERE transaction_item_id IN (SELECT id FROM stock_transaction_items WHERE product_id = $id)");
            $this->pdo->exec("DELETE FROM stock_transaction_items WHERE product_id = $id");
            $this->pdo->exec("DELETE FROM product_batches WHERE product_id = $id");
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

    // ---- Submit ----

    public function testSubmitTransitionsDraftToOrderedViaHttp(): void
    {
        $jar = $this->loggedInUserSession();
        $poId = $this->seedPurchaseOrder('draft');

        $form = $this->httpGet($jar, '/purchase-order/view.php?id=' . $poId);
        $token = $this->extractCsrfToken($form['body']);

        $res = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => $token,
            'action' => 'submit',
            'id' => (string) $poId,
        ]);
        $this->assertSame(302, $res['status'], 'submit must succeed: ' . $res['body']);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('ordered', $stmt->fetchColumn());
    }

    public function testSubmitOnNonDraftPurchaseOrderIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        $poId = $this->seedPurchaseOrder('ordered');

        // view.php renders no CSRF-bearing form at all for a non-draft PO
        // (only a plain Receive link) - pull a valid token from
        // receive.php's own form instead, which is always rendered for an
        // 'ordered' PO with outstanding lines.
        $form = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $token = $this->extractCsrfToken($form['body']);

        $res = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => $token,
            'action' => 'submit',
            'id' => (string) $poId,
        ]);
        $this->assertSame(200, $res['status']);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('ordered', $stmt->fetchColumn(), 'status must be unchanged when submit is rejected');
    }

    public function testSubmitMissingCsrfTokenIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        $poId = $this->seedPurchaseOrder('draft');

        $res = $this->httpPost($jar, '/purchase-order/index.php', [
            'action' => 'submit',
            'id' => (string) $poId,
        ]);
        $this->assertSame(403, $res['status']);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('draft', $stmt->fetchColumn());
    }

    public function testViewerCannotSubmitViaDirectPost(): void
    {
        $jar = $this->loggedInViewerSession();
        $poId = $this->seedPurchaseOrder('draft');

        $res = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => 'irrelevant',
            'action' => 'submit',
            'id' => (string) $poId,
        ]);
        $this->assertNotSame(302, $res['status']);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('draft', $stmt->fetchColumn(), 'a Viewer must never be able to submit a PO');
    }

    // ---- Receive ----

    public function testViewerGetOnReceivePageIsRedirectedAway(): void
    {
        $jar = $this->loggedInViewerSession();
        $poId = $this->seedPurchaseOrder('ordered');

        $res = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $this->assertSame(302, $res['status']);
    }

    public function testViewerCannotReceiveViaDirectPost(): void
    {
        $jar = $this->loggedInViewerSession();
        [$poId, $productId, $poItemId] = $this->seedPurchaseOrderWithItem('ordered', 10);
        $stockBefore = $this->currentStock($productId);

        $res = $this->httpPost($jar, '/purchase-order/receive.php?id=' . $poId, [
            'csrf_token' => 'irrelevant',
            'receive_date' => date('Y-m-d'),
            'note' => '',
            'purchase_order_item_id' => [(string) $poItemId],
            'receive_qty' => ['10'],
            'unit_cost' => ['1.00'],
            'unit_cost_currency' => ['USD'],
            'batch_number' => [''],
            'expiry_date' => [''],
        ]);
        // receive.php's canWrite() gate runs before the request-method
        // check at all, so a Viewer's POST is redirected away (302) just
        // like a GET - the important assertion is what happened in the
        // database, not the transport-level status code.
        $this->assertSame(302, $res['status']);
        $this->assertMatchesRegularExpression('#/purchase-order/index\.php#', $res['headers']);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('ordered', $stmt->fetchColumn());
        $this->assertSame($stockBefore, $this->currentStock($productId), 'a Viewer must never be able to increase stock via receive');
    }

    public function testGetOnReceivePageForANonReceivablePoRedirectsAway(): void
    {
        $jar = $this->loggedInUserSession();
        $poId = $this->seedPurchaseOrder('draft');

        $res = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $this->assertSame(302, $res['status'], 'receive.php must redirect away from a non-receivable (draft) PO');
    }

    public function testFullReceiveViaHttpTransitionsToReceivedAndIncreasesStock(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, $productId, $poItemId] = $this->seedPurchaseOrderWithItem('ordered', 10);
        $stockBefore = $this->currentStock($productId);

        $form = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $this->assertSame(200, $form['status']);
        $token = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);

        $res = $this->httpPost($jar, '/purchase-order/receive.php?id=' . $poId, [
            'csrf_token' => $token,
            'idempotency_token' => $idempotencyToken,
            'receive_date' => date('Y-m-d'),
            'note' => '',
            'purchase_order_item_id' => [(string) $poItemId],
            'receive_qty' => ['10'],
            'unit_cost' => ['1.00'],
            'unit_cost_currency' => ['USD'],
            'batch_number' => [''],
            'expiry_date' => [''],
        ]);
        $this->assertSame(302, $res['status'], 'receive must succeed: ' . $res['body']);
        $this->assertMatchesRegularExpression('#/purchase-order/view\.php\?id=' . $poId . '#', $res['headers']);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('received', $stmt->fetchColumn());
        $this->assertSame($stockBefore + 10, $this->currentStock($productId));
    }

    public function testPartialReceiveViaHttpTransitionsToPartiallyReceived(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, $productId, $poItemId] = $this->seedPurchaseOrderWithItem('ordered', 10);

        $form = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $token = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);

        $res = $this->httpPost($jar, '/purchase-order/receive.php?id=' . $poId, [
            'csrf_token' => $token,
            'idempotency_token' => $idempotencyToken,
            'receive_date' => date('Y-m-d'),
            'note' => '',
            'purchase_order_item_id' => [(string) $poItemId],
            'receive_qty' => ['4'],
            'unit_cost' => ['1.00'],
            'unit_cost_currency' => ['USD'],
            'batch_number' => [''],
            'expiry_date' => [''],
        ]);
        $this->assertSame(302, $res['status'], 'partial receive must succeed: ' . $res['body']);

        $stmt = $this->pdo->prepare('SELECT status, received_qty FROM purchase_orders po JOIN purchase_order_items poi ON poi.purchase_order_id = po.id WHERE po.id = ?');
        $stmt->execute([$poId]);
        $row = $stmt->fetch();
        $this->assertSame('partially_received', $row['status']);
        $this->assertSame(4, (int) $row['received_qty']);

        // Once partially received, the Receive page must still be reachable
        // (not just 'ordered').
        $reGet = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $this->assertSame(200, $reGet['status']);
    }

    public function testReceiveOnFullyReceivedPurchaseOrderRedirectsAway(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, , $poItemId] = $this->seedPurchaseOrderWithItem('ordered', 5);

        $form = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $token = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);
        $res = $this->httpPost($jar, '/purchase-order/receive.php?id=' . $poId, [
            'csrf_token' => $token,
            'idempotency_token' => $idempotencyToken,
            'receive_date' => date('Y-m-d'),
            'note' => '',
            'purchase_order_item_id' => [(string) $poItemId],
            'receive_qty' => ['5'],
            'unit_cost' => ['1.00'],
            'unit_cost_currency' => ['USD'],
            'batch_number' => [''],
            'expiry_date' => [''],
        ]);
        $this->assertSame(302, $res['status']);

        // Now fully received - a subsequent GET on receive.php must
        // redirect away rather than render an empty/broken form.
        $res2 = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $this->assertSame(302, $res2['status']);
    }

    public function testDuplicateReceiveIdempotencyTokenIsRejectedAndDoesNotDoubleStock(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, $productId, $poItemId] = $this->seedPurchaseOrderWithItem('ordered', 10);
        $stockBefore = $this->currentStock($productId);

        $form = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $token = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);

        $fields = [
            'csrf_token' => $token,
            'idempotency_token' => $idempotencyToken,
            'receive_date' => date('Y-m-d'),
            'note' => '',
            'purchase_order_item_id' => [(string) $poItemId],
            'receive_qty' => ['4'],
            'unit_cost' => ['1.00'],
            'unit_cost_currency' => ['USD'],
            'batch_number' => [''],
            'expiry_date' => [''],
        ];

        $res1 = $this->httpPost($jar, '/purchase-order/receive.php?id=' . $poId, $fields);
        $this->assertSame(302, $res1['status'], 'first receive must succeed: ' . $res1['body']);

        // Same CSRF token remains valid for the session; replay the exact
        // same POST (same idempotency_token) a second time.
        $res2 = $this->httpPost($jar, '/purchase-order/receive.php?id=' . $poId, $fields);
        $this->assertSame(200, $res2['status'], 'a replayed idempotency token must be handled gracefully, not crash or redirect as success');

        $this->assertSame($stockBefore + 4, $this->currentStock($productId), 'stock must be increased exactly once, not twice');

        $stmt = $this->pdo->prepare('SELECT received_qty FROM purchase_order_items WHERE id = ?');
        $stmt->execute([$poItemId]);
        $this->assertSame(4, (int) $stmt->fetchColumn(), 'received_qty must reflect exactly one receipt, not two');
    }

    public function testReceiveMissingCsrfTokenIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, $productId, $poItemId] = $this->seedPurchaseOrderWithItem('ordered', 10);
        $stockBefore = $this->currentStock($productId);

        $res = $this->httpPost($jar, '/purchase-order/receive.php?id=' . $poId, [
            'receive_date' => date('Y-m-d'),
            'purchase_order_item_id' => [(string) $poItemId],
            'receive_qty' => ['5'],
            'unit_cost' => ['1.00'],
            'unit_cost_currency' => ['USD'],
        ]);
        $this->assertSame(403, $res['status']);
        $this->assertSame($stockBefore, $this->currentStock($productId));
    }

    public function testOverReceivingViaHttpIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, $productId, $poItemId] = $this->seedPurchaseOrderWithItem('ordered', 5);
        $stockBefore = $this->currentStock($productId);

        $form = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $token = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);

        // Directly tamper the posted quantity beyond what the rendered
        // form's max= attribute would allow - proving server-side
        // enforcement, not merely a client-side HTML constraint.
        $res = $this->httpPost($jar, '/purchase-order/receive.php?id=' . $poId, [
            'csrf_token' => $token,
            'idempotency_token' => $idempotencyToken,
            'receive_date' => date('Y-m-d'),
            'note' => '',
            'purchase_order_item_id' => [(string) $poItemId],
            'receive_qty' => ['999'],
            'unit_cost' => ['1.00'],
            'unit_cost_currency' => ['USD'],
            'batch_number' => [''],
            'expiry_date' => [''],
        ]);
        $this->assertSame(200, $res['status']);

        $stmt = $this->pdo->prepare('SELECT received_qty FROM purchase_order_items WHERE id = ?');
        $stmt->execute([$poItemId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'over-receiving must not mutate received_qty at all');
        $this->assertSame($stockBefore, $this->currentStock($productId));
    }

    public function testTamperedItemIdFromAnotherPurchaseOrderIsRejectedViaHttp(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, $productId, ] = $this->seedPurchaseOrderWithItem('ordered', 5);
        [, , $otherPoItemId] = $this->seedPurchaseOrderWithItem('ordered', 5);
        $stockBefore = $this->currentStock($productId);

        $form = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $token = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);

        $res = $this->httpPost($jar, '/purchase-order/receive.php?id=' . $poId, [
            'csrf_token' => $token,
            'idempotency_token' => $idempotencyToken,
            'receive_date' => date('Y-m-d'),
            'note' => '',
            'purchase_order_item_id' => [(string) $otherPoItemId],
            'receive_qty' => ['5'],
            'unit_cost' => ['1.00'],
            'unit_cost_currency' => ['USD'],
            'batch_number' => [''],
            'expiry_date' => [''],
        ]);
        $this->assertSame(200, $res['status']);

        $this->assertSame($stockBefore, $this->currentStock($productId), 'a tampered item id from another PO must never mutate stock');

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('ordered', $stmt->fetchColumn(), 'the targeted PO must remain unaffected');
    }

    public function testInvalidQuantityViaHttpIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, $productId, $poItemId] = $this->seedPurchaseOrderWithItem('ordered', 5);
        $stockBefore = $this->currentStock($productId);

        $form = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $token = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);

        $res = $this->httpPost($jar, '/purchase-order/receive.php?id=' . $poId, [
            'csrf_token' => $token,
            'idempotency_token' => $idempotencyToken,
            'receive_date' => date('Y-m-d'),
            'note' => '',
            'purchase_order_item_id' => [(string) $poItemId],
            'receive_qty' => ['-3'],
            'unit_cost' => ['1.00'],
            'unit_cost_currency' => ['USD'],
            'batch_number' => [''],
            'expiry_date' => [''],
        ]);
        $this->assertSame(200, $res['status']);

        $stmt = $this->pdo->prepare('SELECT received_qty FROM purchase_order_items WHERE id = ?');
        $stmt->execute([$poItemId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
        $this->assertSame($stockBefore, $this->currentStock($productId));
    }

    // ---- helpers ----

    private function extractIdempotencyToken(string $html): string
    {
        preg_match('/name="idempotency_token" value="([a-f0-9]+)"/', $html, $m);
        return $m[1] ?? '';
    }

    private function currentStock(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    private function seedSupplier(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO suppliers (name) VALUES (?)');
        $stmt->execute(['P2 HTTP Test Supplier ' . bin2hex(random_bytes(3))]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupSupplierIds[] = $id;
        return $id;
    }

    private function seedProduct(): int
    {
        $sku = 'P2-HTTP-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock) VALUES (?,?,?,?,?)');
        $stmt->execute(['P2 HTTP Test Product', $sku, 1, 2, 50]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function seedPurchaseOrder(string $status): int
    {
        [$poId, , ] = $this->seedPurchaseOrderWithItem($status, 5);
        return $poId;
    }

    /** @return array{0:int,1:int,2:int} [poId, productId, poItemId] */
    private function seedPurchaseOrderWithItem(string $status, int $orderedQty): array
    {
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct();
        $stmt = $this->pdo->prepare("INSERT INTO purchase_orders (reference, supplier_id, status, order_date) VALUES (?,?,?,?)");
        $stmt->execute(['PUR-HTTPTEST-' . bin2hex(random_bytes(4)), $supplierId, $status, date('Y-m-d')]);
        $poId = (int) $this->pdo->lastInsertId();
        $this->cleanupPoIds[] = $poId;

        $stmt = $this->pdo->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, ordered_qty, unit_cost) VALUES (?,?,?,1.00)');
        $stmt->execute([$poId, $productId, $orderedQty]);
        $poItemId = (int) $this->pdo->lastInsertId();

        return [$poId, $productId, $poItemId];
    }

    private function loggedInUserSession(): string
    {
        $email = 'p2po.user.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['P2 PO Test User', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }

    private function loggedInViewerSession(): string
    {
        $email = 'p2po.viewer.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,3)');
        $stmt->execute(['P2 PO Test Viewer', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }
}
