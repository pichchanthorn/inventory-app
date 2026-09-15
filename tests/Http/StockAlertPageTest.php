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
    private array $cleanupSupplierIds = [];
    private array $cleanupCategoryIds = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupProductIds as $id) {
            $this->pdo->exec("DELETE FROM stock_transaction_items WHERE product_id = $id");
            $this->pdo->exec("DELETE FROM products WHERE id = $id");
        }
        // Suppliers/categories are removed only after every product
        // referencing them is gone - both FKs are ON DELETE SET NULL, but
        // the delete order here keeps the intent explicit rather than
        // relying on that.
        foreach ($this->cleanupSupplierIds as $id) {
            $this->pdo->exec("DELETE FROM suppliers WHERE id = $id");
        }
        foreach ($this->cleanupCategoryIds as $id) {
            $this->pdo->exec("DELETE FROM categories WHERE id = $id");
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

    // ---- Phase P3-B1: supplier grouping (display only, no PO action) ----

    public function testLowStockProductsAreGroupedUnderTheirSupplier(): void
    {
        $jar = $this->loggedInUserSession();
        // Names chosen so the alphabetical supplier-group ordering is
        // known up front (AAA before BBB) - the assertions below are
        // positional, so they need a deterministic group order, which is
        // exactly what the page's ORDER BY s.name ASC provides.
        $token = bin2hex(random_bytes(4));
        $supplierA = $this->seedSupplier("P3B1 AAA Supplier $token");
        $supplierB = $this->seedSupplier("P3B1 BBB Supplier $token");

        $this->seedProduct("P3B1 Product A $token", 1, 5, null, $supplierA['id']);
        $this->seedProduct("P3B1 Product B $token", 2, 5, null, $supplierA['id']);
        $this->seedProduct("P3B1 Product C $token", 1, 5, null, $supplierB['id']);

        $res = $this->httpGet($jar, '/stock-alert/index.php');
        $this->assertSame(200, $res['status']);
        $body = $res['body'];

        $posSupplierA = strpos($body, $supplierA['name']);
        $posSupplierB = strpos($body, $supplierB['name']);
        $posA = strpos($body, "P3B1 Product A $token");
        $posB = strpos($body, "P3B1 Product B $token");
        $posC = strpos($body, "P3B1 Product C $token");

        $this->assertNotFalse($posSupplierA, 'supplier A group heading must render');
        $this->assertNotFalse($posSupplierB, 'supplier B group heading must render');
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertNotFalse($posC);

        // Both of supplier A's products must fall between A's own heading
        // and the next group's heading - i.e. they are genuinely inside
        // A's section, not merely present somewhere on the page.
        $this->assertGreaterThan($posSupplierA, $posA, 'Product A must render inside supplier A\'s group');
        $this->assertGreaterThan($posSupplierA, $posB, 'Product B must render inside supplier A\'s group');
        $this->assertLessThan($posSupplierB, $posA, 'Product A must not leak into supplier B\'s group');
        $this->assertLessThan($posSupplierB, $posB, 'Product B must not leak into supplier B\'s group');

        // Supplier B's product must fall after B's heading.
        $this->assertGreaterThan($posSupplierB, $posC, 'Product C must render inside supplier B\'s group');
    }

    public function testSupplierlessProductsAppearUnderTheNoSupplierSection(): void
    {
        $jar = $this->loggedInUserSession();
        $token = bin2hex(random_bytes(4));
        $supplier = $this->seedSupplier("P3B1 Grouped Supplier $token");
        $this->seedProduct("P3B1 Has Supplier $token", 1, 5, null, $supplier['id']);
        $this->seedProduct("P3B1 Orphan Product $token", 1, 5, null, null);

        $res = $this->httpGet($jar, '/stock-alert/index.php');
        $this->assertSame(200, $res['status']);
        $body = $res['body'];

        $this->assertStringContainsString('No Supplier', $body, 'the No Supplier section heading must render');
        $this->assertStringContainsString(
            'Assign a supplier on the product',
            $body,
            'the No Supplier section must explain that a supplier has to be assigned first'
        );

        $posNoSupplier = strpos($body, 'No Supplier');
        $posOrphan = strpos($body, "P3B1 Orphan Product $token");
        $posGrouped = strpos($body, "P3B1 Has Supplier $token");

        $this->assertNotFalse($posNoSupplier);
        $this->assertNotFalse($posOrphan);
        $this->assertNotFalse($posGrouped);
        $this->assertGreaterThan($posNoSupplier, $posOrphan, 'a supplier-less product must render inside the No Supplier section');
        $this->assertLessThan($posNoSupplier, $posGrouped, 'the No Supplier section must come after the real supplier groups');
    }

    public function testAProductAboveMinStockIsExcludedAndSoIsItsSupplierGroup(): void
    {
        $jar = $this->loggedInUserSession();
        $token = bin2hex(random_bytes(4));
        // This supplier's ONLY product is comfortably above min_stock, so
        // neither the product nor an empty group heading for it may
        // render - the unchanged current_stock <= min_stock rule decides
        // membership, and grouping never resurrects an excluded row.
        $quietSupplier = $this->seedSupplier("P3B1 Quiet Supplier $token");
        $this->seedProduct("P3B1 Healthy Product $token", 50, 5, null, $quietSupplier['id']);

        $res = $this->httpGet($jar, '/stock-alert/index.php');
        $this->assertSame(200, $res['status']);
        $this->assertStringNotContainsString("P3B1 Healthy Product $token", $res['body']);
        $this->assertStringNotContainsString($quietSupplier['name'], $res['body'], 'a supplier with no low-stock products must not get an empty group');
    }

    public function testGroupedRowStillShowsEveryExistingLowStockField(): void
    {
        $jar = $this->loggedInUserSession();
        $token = bin2hex(random_bytes(4));
        $supplier = $this->seedSupplier("P3B1 Detail Supplier $token");
        $category = $this->seedCategory("P3B1 Detail Category $token");
        $this->seedProduct(
            "P3B1 Detail Product $token",
            0,      // current_stock 0 -> CRITICAL
            7,      // min_stock / reorder level
            30,     // suggested reorder quantity
            $supplier['id'],
            ['category_id' => $category['id'], 'package_size' => "50kg-$token"]
        );

        $res = $this->httpGet($jar, '/stock-alert/index.php');
        $body = $res['body'];

        $this->assertStringContainsString("P3B1 Detail Product $token", $body, 'product name');
        $this->assertMatchesRegularExpression('/L1-ALERT-[0-9a-f]+/', $body, 'SKU');
        $this->assertStringContainsString("50kg-$token", $body, 'package size');
        $this->assertStringContainsString("P3B1 Detail Category $token", $body, 'category');
        $this->assertStringContainsString('>0 pcs', $body, 'current stock');
        $this->assertStringContainsString('>7<', $body, 'reorder level');
        // This cell renders its value on its own line (unlike the inline
        // stock/reorder-level cells above), so the match has to tolerate
        // the surrounding whitespace - anchored to the column's own
        // data-label so it can't accidentally match a "30" elsewhere.
        $this->assertMatchesRegularExpression(
            '/data-label="Suggested Reorder Qty">\s*30\s*</',
            $body,
            'suggested reorder quantity'
        );
        $this->assertStringContainsString('CRITICAL', $body, 'severity badge');
        $this->assertStringContainsString($supplier['name'], $body, 'supplier group heading');
    }

    public function testSupplierNameIsEscapedInTheGroupHeading(): void
    {
        $jar = $this->loggedInUserSession();
        $token = bin2hex(random_bytes(4));
        $supplier = $this->seedSupplier("P3B1 <script>alert('xss')</script> $token");
        $this->seedProduct("P3B1 XSS Probe Product $token", 1, 5, null, $supplier['id']);

        $res = $this->httpGet($jar, '/stock-alert/index.php');
        $body = $res['body'];

        $this->assertStringNotContainsString("<script>alert('xss')</script>", $body, 'a supplier name must never render as live markup');
        $this->assertStringContainsString('&lt;script&gt;', $body, 'the supplier name must render HTML-escaped');
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

    // $supplierId/$extra appended last and defaulted, so every pre-P3-B1
    // call site above keeps its exact original behavior (supplier_id NULL,
    // no extra columns) without being touched - the same "append the new
    // optional parameter last" convention includes/stock.php already uses
    // for its own evolving signatures.
    private function seedProduct(string $name, int $stock, int $minStock, ?int $reorderQuantity, ?int $supplierId = null, array $extra = []): int
    {
        $row = array_merge([
            'name' => $name,
            'sku' => 'L1-ALERT-' . bin2hex(random_bytes(4)),
            'cost_price' => 1,
            'sale_price' => 2,
            'current_stock' => $stock,
            'min_stock' => $minStock,
            'reorder_quantity' => $reorderQuantity,
            'supplier_id' => $supplierId,
        ], $extra);

        $columns = array_keys($row);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $stmt = $this->pdo->prepare('INSERT INTO products (' . implode(',', $columns) . ') VALUES (' . $placeholders . ')');
        $stmt->execute(array_values($row));
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function seedSupplier(string $name): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO suppliers (name) VALUES (?)');
        $stmt->execute([$name]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupSupplierIds[] = $id;
        return ['id' => $id, 'name' => $name];
    }

    private function seedCategory(string $name): array
    {
        $slug = 'p3b1-cat-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO categories (name, slug) VALUES (?,?)');
        $stmt->execute([$name, $slug]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupCategoryIds[] = $id;
        return ['id' => $id, 'name' => $name];
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
