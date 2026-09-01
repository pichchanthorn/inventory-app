<?php
declare(strict_types=1);

namespace Tests\Http;

// P0 #16 (CSRF). Drives the real stock-out/index.php page over real HTTP
// - csrf_verify() (includes/csrf.php) is exercised exactly as it runs in
// production, unmodified. See HttpServerTestCase for why this must be a
// real HTTP request rather than an in-process include.
final class CsrfTest extends HttpServerTestCase
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

    public function testValidCsrfTokenIsAccepted(): void
    {
        [$jar, $product] = $this->loggedInSessionWithProduct();
        $form = $this->httpGet($jar, '/stock-out/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $countBefore = $this->countStockOutTransactions();

        $res = $this->postStockOut($jar, $token, $product);

        $this->assertSame(302, $res['status'], 'a valid CSRF token must be accepted: ' . $res['body']);
        $this->assertSame($countBefore + 1, $this->countStockOutTransactions());
    }

    public function testMissingCsrfTokenIsRejected(): void
    {
        [$jar, $product] = $this->loggedInSessionWithProduct();
        // Establish a valid session (with its own csrf_token) by loading
        // the form first, then submit without the field entirely.
        $this->httpGet($jar, '/stock-out/index.php');
        $countBefore = $this->countStockOutTransactions();

        $res = $this->postStockOut($jar, null, $product);

        $this->assertSame(403, $res['status'], 'a missing CSRF token must be rejected');
        $this->assertSame($countBefore, $this->countStockOutTransactions(), 'a rejected request must perform no mutation');
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        [$jar, $product] = $this->loggedInSessionWithProduct();
        $this->httpGet($jar, '/stock-out/index.php');
        $countBefore = $this->countStockOutTransactions();

        $res = $this->postStockOut($jar, str_repeat('a', 64), $product);

        $this->assertSame(403, $res['status'], 'a token that does not match the session must be rejected');
        $this->assertSame($countBefore, $this->countStockOutTransactions(), 'a rejected request must perform no mutation');
    }

    public function testStaleTokenFromADifferentSessionIsRejected(): void
    {
        [$jarA, ] = $this->loggedInSessionWithProduct();
        [$jarB, $productB] = $this->loggedInSessionWithProduct();

        $formA = $this->httpGet($jarA, '/stock-out/index.php');
        $tokenFromSessionA = $this->extractCsrfToken($formA['body']);
        $this->httpGet($jarB, '/stock-out/index.php'); // establishes session B's own (different) token
        $countBefore = $this->countStockOutTransactions();

        // Session B submits using session A's token - must be rejected.
        $res = $this->postStockOut($jarB, $tokenFromSessionA, $productB);

        $this->assertSame(403, $res['status']);
        $this->assertSame($countBefore, $this->countStockOutTransactions());
    }

    private function countStockOutTransactions(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM stock_transactions WHERE type = 'out'")->fetchColumn();
    }

    private function postStockOut(string $jar, ?string $token, array $product): array
    {
        $fields = [
            'transaction_date' => date('Y-m-d'),
            'note' => 'CSRF test',
            'product_id' => [(string) $product['id']],
            'qty' => ['5'],
            'unit_price' => ['1.00'],
        ];
        if ($token !== null) {
            $fields['csrf_token'] = $token;
        }
        return $this->httpPost($jar, '/stock-out/index.php', $fields);
    }

    /** @return array{0:string,1:array{id:int}} [cookieJar, product] */
    private function loggedInSessionWithProduct(): array
    {
        $email = 'csrf.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['CSRF Test User', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $sku = 'CSRF-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock) VALUES (?,?,?,?,?)');
        $stmt->execute(['CSRF Test Product', $sku, 1, 1, 50]);
        $productId = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $productId;

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);

        return [$jar, ['id' => $productId]];
    }
}
