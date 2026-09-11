<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase P1 (Purchase / Supplier Order Management - draft-only PO core).
// Drives the real purchase-order/{index,create,edit}.php pages over real
// HTTP - see HttpServerTestCase for why a real HTTP request (rather than
// an in-process include) is used throughout this suite: csrf_verify()/
// auth_check.php call exit()/header() directly.
final class PurchaseOrderHttpTest extends HttpServerTestCase
{
    private array $cleanupPoIds = [];
    private array $cleanupProductIds = [];
    private array $cleanupSupplierIds = [];
    private array $cleanupUserIds = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupPoIds as $id) {
            $this->pdo->exec("DELETE FROM audit_log WHERE entity_type = 'purchase_order' AND entity_id = $id");
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

    public function testCreatingADraftPurchaseOrderSucceedsAndRedirectsToDetail(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct();

        $form = $this->httpGet($jar, '/purchase-order/create.php');
        $this->assertSame(200, $form['status']);
        $token = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);

        $res = $this->httpPost($jar, '/purchase-order/create.php', [
            'csrf_token' => $token,
            'idempotency_token' => $idempotencyToken,
            'supplier_id' => (string) $supplierId,
            'order_date' => date('Y-m-d'),
            'expected_date' => '',
            'note' => 'http test',
            'product_id' => [(string) $productId],
            'ordered_qty' => ['4'],
            'unit_cost' => ['2.00'],
            'unit_cost_currency' => ['USD'],
        ]);
        $this->assertSame(302, $res['status'], 'create must succeed: ' . $res['body']);
        $this->assertMatchesRegularExpression('#/purchase-order/view\.php\?id=\d+#', $res['headers']);

        preg_match('/Location:.*?id=(\d+)/', $res['headers'], $m);
        $poId = (int) $m[1];
        $this->cleanupPoIds[] = $poId;

        $stmt = $this->pdo->prepare('SELECT reference, status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $row = $stmt->fetch();
        $this->assertStringStartsWith('PUR-', $row['reference']);
        $this->assertSame('draft', $row['status']);
    }

    public function testDuplicateCreateSubmissionWithTheSameIdempotencyTokenCreatesExactlyOnePurchaseOrder(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct();

        $form = $this->httpGet($jar, '/purchase-order/create.php');
        $token = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);

        $fields = [
            'csrf_token' => $token,
            'idempotency_token' => $idempotencyToken,
            'supplier_id' => (string) $supplierId,
            'order_date' => date('Y-m-d'),
            'expected_date' => '',
            'note' => 'duplicate test',
            'product_id' => [(string) $productId],
            'ordered_qty' => ['1'],
            'unit_cost' => ['1.00'],
            'unit_cost_currency' => ['USD'],
        ];

        $countBefore = $this->countPurchaseOrders();
        $res1 = $this->httpPost($jar, '/purchase-order/create.php', $fields);
        $this->assertSame(302, $res1['status'], 'first submission must succeed: ' . $res1['body']);
        preg_match('/Location:.*?id=(\d+)/', $res1['headers'], $m);
        $this->cleanupPoIds[] = (int) $m[1];

        $res2 = $this->httpPost($jar, '/purchase-order/create.php', $fields);
        $this->assertSame(200, $res2['status'], 'a replayed token must be handled gracefully, not crash');
        $this->assertStringContainsString('already created', $res2['body']);

        $this->assertSame($countBefore + 1, $this->countPurchaseOrders(), 'exactly one PO must exist after the duplicate submission');
    }

    public function testMissingCsrfTokenIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct();
        $countBefore = $this->countPurchaseOrders();

        $res = $this->httpPost($jar, '/purchase-order/create.php', [
            'supplier_id' => (string) $supplierId,
            'order_date' => date('Y-m-d'),
            'product_id' => [(string) $productId],
            'ordered_qty' => ['1'],
            'unit_cost' => ['1.00'],
        ]);
        $this->assertSame(403, $res['status']);
        $this->assertSame($countBefore, $this->countPurchaseOrders());
    }

    public function testInvalidCsrfTokenIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct();
        $countBefore = $this->countPurchaseOrders();

        $res = $this->httpPost($jar, '/purchase-order/create.php', [
            'csrf_token' => 'not-a-real-token',
            'supplier_id' => (string) $supplierId,
            'order_date' => date('Y-m-d'),
            'product_id' => [(string) $productId],
            'ordered_qty' => ['1'],
            'unit_cost' => ['1.00'],
        ]);
        $this->assertSame(403, $res['status']);
        $this->assertSame($countBefore, $this->countPurchaseOrders());
    }

    // ---- RBAC ----

    public function testViewerCanLoadTheListPage(): void
    {
        $jar = $this->loggedInViewerSession();
        $res = $this->httpGet($jar, '/purchase-order/index.php');
        $this->assertSame(200, $res['status']);
        $this->assertStringNotContainsString('po_create_button', $res['body']);
    }

    public function testViewerListPageShowsNoWriteControls(): void
    {
        $jar = $this->loggedInViewerSession();
        $res = $this->httpGet($jar, '/purchase-order/index.php');
        $this->assertStringNotContainsString('purchase-order/create.php', $res['body'], 'Viewer must not see a Create link');
    }

    public function testViewerCannotCreateViaDirectPost(): void
    {
        $jar = $this->loggedInViewerSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct();
        $countBefore = $this->countPurchaseOrders();

        // A Viewer's create.php GET redirects away before a CSRF token
        // is ever rendered - simulate a direct POST attempt with a
        // forged-but-otherwise-well-formed request to prove the
        // server-side canWrite() gate, not just the missing token.
        $res = $this->httpPost($jar, '/purchase-order/create.php', [
            'csrf_token' => 'irrelevant',
            'supplier_id' => (string) $supplierId,
            'order_date' => date('Y-m-d'),
            'product_id' => [(string) $productId],
            'ordered_qty' => ['1'],
            'unit_cost' => ['1.00'],
        ]);
        $this->assertNotSame(200, $res['status']);
        $this->assertSame($countBefore, $this->countPurchaseOrders(), 'a Viewer must never be able to create a PO');
    }

    public function testViewerGetOnCreatePageIsRedirectedAway(): void
    {
        $jar = $this->loggedInViewerSession();
        $res = $this->httpGet($jar, '/purchase-order/create.php');
        $this->assertSame(302, $res['status']);
    }

    public function testViewerGetOnEditPageIsRedirectedAway(): void
    {
        $jar = $this->loggedInViewerSession();
        $poId = $this->seedDraftPurchaseOrder();
        $res = $this->httpGet($jar, '/purchase-order/edit.php?id=' . $poId);
        $this->assertSame(302, $res['status']);
    }

    public function testViewerCannotDeleteViaDirectPost(): void
    {
        $jar = $this->loggedInViewerSession();
        $poId = $this->seedDraftPurchaseOrder();

        $res = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => 'irrelevant',
            'action' => 'delete',
            'id' => (string) $poId,
        ]);
        $this->assertNotSame(200, $res['status']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'the PO must survive a Viewer delete attempt');
    }

    public function testUserCanCreateEditAndDeleteADraftPurchaseOrder(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct();

        $form = $this->httpGet($jar, '/purchase-order/create.php');
        $token = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);
        $createRes = $this->httpPost($jar, '/purchase-order/create.php', [
            'csrf_token' => $token,
            'idempotency_token' => $idempotencyToken,
            'supplier_id' => (string) $supplierId,
            'order_date' => date('Y-m-d'),
            'expected_date' => '',
            'note' => '',
            'product_id' => [(string) $productId],
            'ordered_qty' => ['2'],
            'unit_cost' => ['1.00'],
            'unit_cost_currency' => ['USD'],
        ]);
        preg_match('/Location:.*?id=(\d+)/', $createRes['headers'], $m);
        $poId = (int) $m[1];
        $this->cleanupPoIds[] = $poId;

        $editForm = $this->httpGet($jar, '/purchase-order/edit.php?id=' . $poId);
        $this->assertSame(200, $editForm['status']);
        $editToken = $this->extractCsrfToken($editForm['body']);
        $editRes = $this->httpPost($jar, '/purchase-order/edit.php?id=' . $poId, [
            'csrf_token' => $editToken,
            'supplier_id' => (string) $supplierId,
            'order_date' => date('Y-m-d'),
            'expected_date' => '',
            'note' => 'edited via http',
            'product_id' => [(string) $productId],
            'ordered_qty' => ['5'],
            'unit_cost' => ['3.00'],
            'unit_cost_currency' => ['USD'],
        ]);
        $this->assertSame(302, $editRes['status'], 'edit must succeed: ' . $editRes['body']);

        $stmt = $this->pdo->prepare('SELECT note FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('edited via http', $stmt->fetchColumn());

        $viewRes = $this->httpGet($jar, '/purchase-order/view.php?id=' . $poId);
        $deleteToken = $this->extractCsrfToken($viewRes['body']);
        $deleteRes = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => $deleteToken,
            'action' => 'delete',
            'id' => (string) $poId,
        ]);
        $this->assertSame(302, $deleteRes['status']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    // ---- Draft-only guards ----

    public function testEditingANonDraftPurchaseOrderIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        $poId = $this->seedDraftPurchaseOrder();
        $this->pdo->exec("UPDATE purchase_orders SET status = 'ordered' WHERE id = $poId");

        $res = $this->httpGet($jar, '/purchase-order/edit.php?id=' . $poId);
        $this->assertSame(302, $res['status'], 'edit.php must redirect away from a non-draft PO rather than render the form');

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('ordered', $stmt->fetchColumn());
    }

    public function testDeletingANonDraftPurchaseOrderIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInUserSession();
        $poId = $this->seedDraftPurchaseOrder();
        $this->pdo->exec("UPDATE purchase_orders SET status = 'cancelled' WHERE id = $poId");

        // A cancelled PO's view page renders no Delete form/CSRF token at
        // all for it (canWrite() && status==='draft' gate) - the CSRF
        // token itself is per-session, not per-PO, so any other page
        // that renders one works equally well here; this proves the
        // SERVER-SIDE draft-only guard rejects the request even with an
        // otherwise-valid CSRF token, not merely that no token exists.
        $form = $this->httpGet($jar, '/purchase-order/create.php');
        $token = $this->extractCsrfToken($form['body']);

        $res = $this->httpPost($jar, '/purchase-order/index.php', [
            'csrf_token' => $token,
            'action' => 'delete',
            'id' => (string) $poId,
        ]);
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('draft', strtolower($res['body']));

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'a cancelled PO must survive a delete attempt');
    }

    public function testAttemptedStatusManipulationViaExtraPostFieldIsIgnored(): void
    {
        $jar = $this->loggedInUserSession();
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct();

        $form = $this->httpGet($jar, '/purchase-order/create.php');
        $token = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);

        $res = $this->httpPost($jar, '/purchase-order/create.php', [
            'csrf_token' => $token,
            'idempotency_token' => $idempotencyToken,
            'supplier_id' => (string) $supplierId,
            'order_date' => date('Y-m-d'),
            'expected_date' => '',
            'note' => '',
            'status' => 'received', // forged field - the handler has no
                                     // code path that reads $_POST['status']
                                     // at all; this proves that, not just
                                     // documents it.
            'product_id' => [(string) $productId],
            'ordered_qty' => ['1'],
            'unit_cost' => ['1.00'],
            'unit_cost_currency' => ['USD'],
        ]);
        preg_match('/Location:.*?id=(\d+)/', $res['headers'], $m);
        $this->assertNotEmpty($m, 'create must still succeed: ' . $res['body']);
        $poId = (int) $m[1];
        $this->cleanupPoIds[] = $poId;

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $this->assertSame('draft', $stmt->fetchColumn(), 'a forged status field must never change what gets written');
    }

    // ---- helpers ----

    private function extractIdempotencyToken(string $html): string
    {
        preg_match('/name="idempotency_token" value="([a-f0-9]+)"/', $html, $m);
        return $m[1] ?? '';
    }

    private function countPurchaseOrders(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn();
    }

    private function seedSupplier(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO suppliers (name) VALUES (?)');
        $stmt->execute(['P1 HTTP Test Supplier ' . bin2hex(random_bytes(3))]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupSupplierIds[] = $id;
        return $id;
    }

    private function seedProduct(): int
    {
        $sku = 'P1-HTTP-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock) VALUES (?,?,?,?,?)');
        $stmt->execute(['P1 HTTP Test Product', $sku, 1, 2, 50]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function seedDraftPurchaseOrder(): int
    {
        $supplierId = $this->seedSupplier();
        $productId = $this->seedProduct();
        $stmt = $this->pdo->prepare("INSERT INTO purchase_orders (reference, supplier_id, status, order_date) VALUES (?,?,'draft',?)");
        $stmt->execute(['PUR-HTTPTEST-' . bin2hex(random_bytes(4)), $supplierId, date('Y-m-d')]);
        $poId = (int) $this->pdo->lastInsertId();
        $this->cleanupPoIds[] = $poId;

        $stmt = $this->pdo->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, ordered_qty, unit_cost) VALUES (?,?,1,1.00)');
        $stmt->execute([$poId, $productId]);

        return $poId;
    }

    private function loggedInUserSession(): string
    {
        $email = 'p1po.user.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['P1 PO Test User', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }

    private function loggedInViewerSession(): string
    {
        $email = 'p1po.viewer.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,3)');
        $stmt->execute(['P1 PO Test Viewer', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }
}
