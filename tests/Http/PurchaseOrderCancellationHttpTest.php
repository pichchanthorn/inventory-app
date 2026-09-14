<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase P3-A (Purchase Order Cancellation).
// Drives the real purchase-order/{index,view}.php pages over real HTTP -
// see HttpServerTestCase for why a real HTTP request (rather than an
// in-process include) is used throughout this suite: csrf_verify()/
// auth_check.php call exit()/header() directly.
final class PurchaseOrderCancellationHttpTest extends HttpServerTestCase
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

    // ---- Happy path ----

    public function testUserWithWritePermissionCanCancelAnOrderedPurchaseOrder(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, ,] = $this->seedPurchaseOrderWithItem('ordered', 10);

        $form = $this->httpGet($jar, '/purchase-order/view.php?id=' . $poId);
        $this->assertSame(200, $form['status']);
        $token = $this->extractCsrfToken($form['body']);

        $res = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => $token,
            'action' => 'cancel',
            'id' => (string) $poId,
        ]);
        $this->assertSame(302, $res['status'], 'cancel must succeed: ' . $res['body']);
        $this->assertMatchesRegularExpression('#/purchase-order/view\.php\?id=' . $poId . '#', $res['headers'], 'must redirect back to the PO detail page (PRG pattern)');

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('cancelled', $stmt->fetchColumn());
    }

    public function testUserCanCancelAPartiallyReceivedPurchaseOrderWithNoStockChange(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, $productId, $poItemId] = $this->seedPurchaseOrderWithItem('ordered', 10);

        $form = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $token = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);
        $this->httpPost($jar, '/purchase-order/receive.php?id=' . $poId, [
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
        $stockBefore = $this->currentStock($productId);

        $viewForm = $this->httpGet($jar, '/purchase-order/view.php?id=' . $poId);
        $cancelToken = $this->extractCsrfToken($viewForm['body']);
        $res = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => $cancelToken,
            'action' => 'cancel',
            'id' => (string) $poId,
        ]);
        $this->assertSame(302, $res['status'], 'cancel of a partially received PO must succeed: ' . $res['body']);

        $stmt = $this->pdo->prepare('SELECT status, received_qty FROM purchase_orders po JOIN purchase_order_items poi ON poi.purchase_order_id = po.id WHERE po.id = ?');
        $stmt->execute([$poId]);
        $row = $stmt->fetch();
        $this->assertSame('cancelled', $row['status']);
        $this->assertSame(4, (int) $row['received_qty'], 'received_qty must remain exactly as it was');
        $this->assertSame($stockBefore, $this->currentStock($productId), 'stock must be unaffected by cancellation');
    }

    // ---- RBAC ----

    public function testViewerCannotCancelViaDirectPost(): void
    {
        $jar = $this->loggedInViewerSession();
        [$poId, ,] = $this->seedPurchaseOrderWithItem('ordered', 10);

        $res = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => 'irrelevant',
            'action' => 'cancel',
            'id' => (string) $poId,
        ]);
        $this->assertNotSame(302, $res['status'], 'a Viewer must never be able to cancel a PO');

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('ordered', $stmt->fetchColumn());
    }

    public function testViewerCancelListPageShowsNoCancelForm(): void
    {
        $jar = $this->loggedInViewerSession();
        [$poId, ,] = $this->seedPurchaseOrderWithItem('ordered', 10);

        $res = $this->httpGet($jar, '/purchase-order/view.php?id=' . $poId);
        $this->assertStringNotContainsString('value="cancel"', $res['body'], 'Viewer must not see a Cancel form/button');
    }

    // ---- CSRF ----

    public function testCancelMissingCsrfTokenIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, ,] = $this->seedPurchaseOrderWithItem('ordered', 10);

        $res = $this->httpPost($jar, '/purchase-order/index.php', [
            'action' => 'cancel',
            'id' => (string) $poId,
        ]);
        $this->assertSame(403, $res['status']);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('ordered', $stmt->fetchColumn());
    }

    public function testCancelInvalidCsrfTokenIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, ,] = $this->seedPurchaseOrderWithItem('ordered', 10);

        $res = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => 'not-a-real-token',
            'action' => 'cancel',
            'id' => (string) $poId,
        ]);
        $this->assertSame(403, $res['status']);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('ordered', $stmt->fetchColumn());
    }

    // ---- GET must never mutate ----

    public function testGetOnIndexWithCancelActionParamsDoesNotMutate(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, ,] = $this->seedPurchaseOrderWithItem('ordered', 10);

        $res = $this->httpGet($jar, '/purchase-order/index.php?action=cancel&id=' . $poId);
        $this->assertSame(200, $res['status']);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('ordered', $stmt->fetchColumn(), 'a GET request must never be able to cancel a PO');
    }

    // ---- Invalid status ----

    public function testCancelOnDraftPurchaseOrderIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        $poId = $this->seedPurchaseOrder('draft');

        $form = $this->httpGet($jar, '/purchase-order/view.php?id=' . $poId);
        $token = $this->extractCsrfToken($form['body']);

        $res = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => $token,
            'action' => 'cancel',
            'id' => (string) $poId,
        ]);
        $this->assertSame(200, $res['status']);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('draft', $stmt->fetchColumn(), 'a draft PO must remain draft - only Delete removes a draft');
    }

    public function testCancelOnReceivedPurchaseOrderIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, , $poItemId] = $this->seedPurchaseOrderWithItem('ordered', 5);

        $receiveForm = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $receiveToken = $this->extractCsrfToken($receiveForm['body']);
        $idempotencyToken = $this->extractIdempotencyToken($receiveForm['body']);
        $this->httpPost($jar, '/purchase-order/receive.php?id=' . $poId, [
            'csrf_token' => $receiveToken,
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

        $viewForm = $this->httpGet($jar, '/purchase-order/view.php?id=' . $poId);
        $this->assertStringNotContainsString('value="cancel"', $viewForm['body'], 'a fully received PO must not render a Cancel form');

        // view.php renders no CSRF-bearing form at all for a fully
        // received PO (no Edit/Submit/Delete/Receive/Cancel action
        // applies) - pull a valid token from create.php's own form
        // instead (always rendered, regardless of any PO's state), then
        // directly POST anyway (tampering past the UI) to prove
        // server-side rejection rather than mere button absence.
        $tokenForm = $this->httpGet($jar, '/purchase-order/create.php');
        $token = $this->extractCsrfToken($tokenForm['body']);
        $res = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => $token,
            'action' => 'cancel',
            'id' => (string) $poId,
        ]);
        $this->assertSame(200, $res['status']);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('received', $stmt->fetchColumn());
    }

    public function testCancelOnAlreadyCancelledPurchaseOrderIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, ,] = $this->seedPurchaseOrderWithItem('ordered', 10);

        $form = $this->httpGet($jar, '/purchase-order/view.php?id=' . $poId);
        $token = $this->extractCsrfToken($form['body']);
        $res1 = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => $token,
            'action' => 'cancel',
            'id' => (string) $poId,
        ]);
        $this->assertSame(302, $res1['status']);

        // Same CSRF token remains valid for the session; attempt a second
        // cancel against the now-cancelled PO.
        $res2 = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => $token,
            'action' => 'cancel',
            'id' => (string) $poId,
        ]);
        $this->assertSame(200, $res2['status']);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'purchase_order' AND entity_id = ? AND action = 'update'");
        $stmt->execute([$poId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'a rejected repeat cancellation must not create a second audit row');
    }

    // ---- Tampering ----

    public function testTamperedPurchaseOrderIdIsRejectedSafely(): void
    {
        $jar = $this->loggedInUserSession();
        $form = $this->httpGet($jar, '/purchase-order/create.php');
        $token = $this->extractCsrfToken($form['body']);

        $res = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => $token,
            'action' => 'cancel',
            'id' => '999999999',
        ]);
        $this->assertSame(200, $res['status']);
    }

    // ---- Cancelled PO cannot then be received ----

    public function testCancelledPurchaseOrderCannotSubsequentlyBeReceivedViaHttp(): void
    {
        $jar = $this->loggedInUserSession();
        [$poId, $productId, $poItemId] = $this->seedPurchaseOrderWithItem('ordered', 10);
        $stockBefore = $this->currentStock($productId);

        $form = $this->httpGet($jar, '/purchase-order/view.php?id=' . $poId);
        $token = $this->extractCsrfToken($form['body']);
        $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => $token,
            'action' => 'cancel',
            'id' => (string) $poId,
        ]);

        // receive.php must redirect away from a now-cancelled PO.
        $res = $this->httpGet($jar, '/purchase-order/receive.php?id=' . $poId);
        $this->assertSame(302, $res['status']);
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
        $stmt->execute(['P3A HTTP Test Supplier ' . bin2hex(random_bytes(3))]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupSupplierIds[] = $id;
        return $id;
    }

    private function seedProduct(): int
    {
        $sku = 'P3A-HTTP-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock) VALUES (?,?,?,?,?)');
        $stmt->execute(['P3A HTTP Test Product', $sku, 1, 2, 50]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function seedPurchaseOrder(string $status): int
    {
        [$poId, ,] = $this->seedPurchaseOrderWithItem($status, 5);
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
        $email = 'p3a.user.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['P3A PO Test User', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }

    private function loggedInViewerSession(): string
    {
        $email = 'p3a.viewer.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,3)');
        $stmt->execute(['P3A PO Test Viewer', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }
}
