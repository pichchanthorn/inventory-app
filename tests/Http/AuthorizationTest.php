<?php
declare(strict_types=1);

namespace Tests\Http;

// P0 #15 (RBAC). Drives the real page-controllers over real HTTP
// requests (see HttpServerTestCase for why) - never calls canWrite()/
// isAdmin() or includes/stock.php directly, since the thing under test
// here is the actual authorization boundary as enforced by the
// application, not the helper functions in isolation.
final class AuthorizationTest extends HttpServerTestCase
{
    private array $cleanupUserIds = [];
    private array $cleanupProductIds = [];

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

    public function testAdminCanAccessAnAdminOnlyPage(): void
    {
        $admin = $this->seedUser(1);
        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);

        $res = $this->httpGet($jar, '/settings/index.php');

        $this->assertSame(200, $res['status'], 'an Admin must be able to load the Settings page');
        $this->assertStringNotContainsString('Location:', $res['headers']);
    }

    public function testUserRoleCannotAccessAnAdminOnlyPage(): void
    {
        $user = $this->seedUser(2);
        $jar = $this->newCookieJar();
        $this->login($jar, $user['email'], $user['password']);

        $res = $this->httpGet($jar, '/settings/index.php');

        $this->assertSame(302, $res['status'], 'a non-Admin must be redirected away from an Admin-only page');
        $this->assertStringContainsString('dashboard.php', $res['headers']);
    }

    public function testViewerCannotAccessAnAdminOnlyPage(): void
    {
        $viewer = $this->seedUser(3);
        $jar = $this->newCookieJar();
        $this->login($jar, $viewer['email'], $viewer['password']);

        $res = $this->httpGet($jar, '/settings/index.php');

        $this->assertSame(302, $res['status']);
        $this->assertStringContainsString('dashboard.php', $res['headers']);
    }

    public function testUserRoleCanPerformAPermittedOperationalWriteOperation(): void
    {
        $user = $this->seedUser(2);
        $product = $this->seedProduct(50);
        $jar = $this->newCookieJar();
        $this->login($jar, $user['email'], $user['password']);

        $form = $this->httpGet($jar, '/stock-out/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $countBefore = $this->countStockOutTransactions();

        $res = $this->httpPost($jar, '/stock-out/index.php', [
            'csrf_token' => $token,
            'transaction_date' => date('Y-m-d'),
            'note' => 'RBAC test - User role',
            'product_id' => [(string) $product['id']],
            'qty' => ['5'],
            'unit_price' => ['1.00'],
        ]);

        $this->assertSame(302, $res['status'], 'a User-role write must succeed: ' . $res['body']);
        $this->assertSame($countBefore + 1, $this->countStockOutTransactions());
    }

    public function testViewerCannotPerformAWriteOperation(): void
    {
        $viewer = $this->seedUser(3);
        $product = $this->seedProduct(50);
        $jar = $this->newCookieJar();
        $this->login($jar, $viewer['email'], $viewer['password']);

        $form = $this->httpGet($jar, '/stock-out/index.php');
        $this->assertSame(200, $form['status'], 'a Viewer must still be able to view the page (read-only access)');
        $token = $this->extractCsrfToken($form['body']);
        $countBefore = $this->countStockOutTransactions();

        $res = $this->httpPost($jar, '/stock-out/index.php', [
            'csrf_token' => $token,
            'transaction_date' => date('Y-m-d'),
            'note' => 'RBAC test - Viewer role',
            'product_id' => [(string) $product['id']],
            'qty' => ['5'],
            'unit_price' => ['1.00'],
        ]);

        // The canWrite() gate re-renders the same page (200) with an
        // error rather than redirecting - the meaningful assertion is
        // that no mutation happened, not the exact HTTP status.
        $this->assertSame($countBefore, $this->countStockOutTransactions(), 'a Viewer write attempt must not create a stock transaction');

        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$product['id']]);
        $this->assertSame(50, (int) $stmt->fetchColumn(), 'stock must be unchanged after a rejected Viewer write attempt');
    }

    private function countStockOutTransactions(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM stock_transactions WHERE type = 'out'")->fetchColumn();
    }

    /** @return array{id:int,email:string,password:string} */
    private function seedUser(int $roleId): array
    {
        $email = 'rbac.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,?)');
        $stmt->execute(['RBAC Test User', $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupUserIds[] = $id;
        return ['id' => $id, 'email' => $email, 'password' => $password];
    }

    /** @return array{id:int} */
    private function seedProduct(int $stock): array
    {
        $sku = 'RBAC-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock) VALUES (?,?,?,?,?)');
        $stmt->execute(['RBAC Test Product', $sku, 1, 1, $stock]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return ['id' => $id];
    }
}
