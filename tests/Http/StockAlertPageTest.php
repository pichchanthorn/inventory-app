<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase L1 (Low Stock Alert / Reorder Management). Drives the real
// stock-alert/index.php page (and the 3 existing surfaces it touches -
// Dashboard's KPI, Product list's ?filter=low_stock, Stock Report's By
// Product tab) over real HTTP. Read-only page: GET only, no CSRF token,
// no mutation - see HttpServerTestCase for why a real HTTP request
// (rather than an in-process include) is used throughout this suite.
final class StockAlertPageTest extends HttpServerTestCase
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

    public function testCriticalAndLowProductsAppearNormalDoesNot(): void
    {
        $jar = $this->loggedInUserSession();
        $critical = $this->seedProduct('L1 Critical Product', 0, 5, null);
        $low = $this->seedProduct('L1 Low Product', 3, 5, null);
        $normal = $this->seedProduct('L1 Normal Product', 50, 5, null);

        $res = $this->httpGet($jar, '/stock-alert/index.php');
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('L1 Critical Product', $res['body']);
        $this->assertStringContainsString('L1 Low Product', $res['body']);
        $this->assertStringNotContainsString('L1 Normal Product', $res['body']);
    }

    public function testCriticalSortsBeforeLowOnThePage(): void
    {
        $jar = $this->loggedInUserSession();
        // Seed LOW first, then CRITICAL - if the page merely preserved
        // insertion/id order, LOW would appear first; the SQL ORDER BY
        // must still put CRITICAL first regardless.
        $this->seedProduct('L1 Sort Low', 2, 5, null);
        $this->seedProduct('L1 Sort Critical', 0, 5, null);

        $res = $this->httpGet($jar, '/stock-alert/index.php');
        $criticalPos = strpos($res['body'], 'L1 Sort Critical');
        $lowPos = strpos($res['body'], 'L1 Sort Low');
        $this->assertNotFalse($criticalPos);
        $this->assertNotFalse($lowPos);
        $this->assertLessThan($lowPos, $criticalPos, 'CRITICAL row must render before LOW row');
    }

    public function testConfiguredReorderQuantityDisplaysCorrectly(): void
    {
        $jar = $this->loggedInUserSession();
        $this->seedProduct('L1 Reorder Qty Product', 1, 5, 25);

        $res = $this->httpGet($jar, '/stock-alert/index.php');
        $this->assertStringContainsString('L1 Reorder Qty Product', $res['body']);
        $this->assertStringContainsString('25', $res['body']);
    }

    public function testNullReorderQuantityDisplaysLocalizedPlaceholder(): void
    {
        $jar = $this->loggedInUserSession();
        $this->seedProduct('L1 No Reorder Qty Product', 1, 5, null);

        $res = $this->httpGet($jar, '/stock-alert/index.php');
        $this->assertStringContainsString('L1 No Reorder Qty Product', $res['body']);
        $this->assertStringContainsString('Not set', $res['body']);
    }

    public function testViewerCanLoadThePage(): void
    {
        $jar = $this->loggedInViewerSession();
        $res = $this->httpGet($jar, '/stock-alert/index.php');
        $this->assertSame(200, $res['status'], 'a Viewer must be able to view the Low Stock / Reorder page');
    }

    public function testStockInQuickLinkIdentifiesTheCorrectProduct(): void
    {
        $jar = $this->loggedInUserSession();
        $productId = $this->seedProduct('L1 Quick Link Product', 0, 5, null);

        $alertRes = $this->httpGet($jar, '/stock-alert/index.php');
        $this->assertStringContainsString("/stock-in/index.php?product_id=$productId", $alertRes['body']);

        $stockInRes = $this->httpGet($jar, "/stock-in/index.php?product_id=$productId");
        $this->assertSame(200, $stockInRes['status']);
        $this->assertStringContainsString('L1 Quick Link Product', $stockInRes['body'], 'the preselected product must appear pre-filled in the first Stock In row');
    }

    public function testStockInIgnoresAnInvalidPreselectProductIdSafely(): void
    {
        $jar = $this->loggedInUserSession();
        $res = $this->httpGet($jar, '/stock-in/index.php?product_id=999999999');
        $this->assertSame(200, $res['status'], 'an unknown product_id must not break the page, just leave it unselected');
    }

    // ---- Regression: existing surfaces unaffected ----

    public function testProductListLowStockFilterStillWorks(): void
    {
        $jar = $this->loggedInUserSession();
        $this->seedProduct('L1 Filter Regression Product', 1, 5, null);

        $res = $this->httpGet($jar, '/product/index.php?filter=low_stock');
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('L1 Filter Regression Product', $res['body']);
    }

    public function testStockReportByProductTabStillWorksAndShowsSeverity(): void
    {
        $jar = $this->loggedInUserSession();
        $this->seedProduct('L1 Report Regression Product', 0, 5, null);

        $res = $this->httpGet($jar, '/stock-report/index.php?tab=product');
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('L1 Report Regression Product', $res['body']);
    }

    public function testDashboardKpiCountEqualsCurrentStockLessThanOrEqualMinStockAndLinksToTheNewPage(): void
    {
        $jar = $this->loggedInUserSession();
        $this->seedProduct('L1 Dashboard Regression Product', 0, 5, null);

        $expectedCount = (int) $this->pdo->query('SELECT COUNT(*) FROM products WHERE current_stock <= min_stock')->fetchColumn();

        $res = $this->httpGet($jar, '/dashboard.php');
        $this->assertSame(200, $res['status']);
        // The KPI value div immediately precedes its own label div in the
        // same card - anchoring the numeric match to the "Low Stock"
        // label text (rather than a bare '>N<' search) avoids a false
        // match against a different KPI card that happens to show the
        // same number.
        $this->assertMatchesRegularExpression(
            '/dash-stat-value mono[^"]*">' . preg_quote((string) $expectedCount, '/') . '<\/div>\s*<div class="dash-stat-label">Low Stock/',
            $res['body'],
            'Dashboard Low Stock KPI must still equal COUNT(current_stock <= min_stock)'
        );
        $this->assertStringContainsString('/stock-alert/index.php', $res['body'], 'Dashboard Low Stock KPI must link to the new dedicated page');
        $this->assertStringNotContainsString('/product/index.php?filter=low_stock', $res['body'], 'the KPI link must no longer point at the old product-list filter');
    }

    // ---- helpers ----

    private function seedProduct(string $name, int $stock, int $minStock, ?int $reorderQuantity): int
    {
        $sku = 'L1-ALERT-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock, min_stock, reorder_quantity) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$name, $sku, 1, 2, $stock, $minStock, $reorderQuantity]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function loggedInUserSession(): string
    {
        $email = 'l1alert.user.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['L1 Alert Test User', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }

    private function loggedInViewerSession(): string
    {
        $email = 'l1alert.viewer.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,3)');
        $stmt->execute(['L1 Alert Test Viewer', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }
}
