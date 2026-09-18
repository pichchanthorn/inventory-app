<?php
declare(strict_types=1);

namespace Tests\Http;

// V2-B2 - Audit Log UX & History. Drives the real audit/index.php over
// real HTTP (see HttpServerTestCase for why) - RBAC, filters, pagination,
// and ordering are all page-controller concerns that live entirely
// inside that file, so they can't be exercised as plain function calls
// the way tests/Integration/DebtAuditTest.php tests logAudit() itself.
//
// Every test here seeds its own audit_log rows with a unique, per-test
// entity_type "marker" string (never reused by any real module) and
// filters by that marker, rather than asserting on the raw, shared
// audit_log table - so accumulated rows from other tests/modules can
// never affect these assertions, same isolation philosophy as
// tests/Integration/TestCase.php's own header comment.
final class AuditLogHttpTest extends HttpServerTestCase
{
    private array $cleanupUserIds = [];
    private array $cleanupAuditIds = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupAuditIds as $id) {
            $this->pdo->exec("DELETE FROM audit_log WHERE id = $id");
        }
        foreach ($this->cleanupUserIds as $id) {
            $this->pdo->exec("DELETE FROM users WHERE id = $id");
        }
        parent::tearDown();
    }

    public function testNonAdminIsRedirectedAwayFromAuditLog(): void
    {
        $viewer = $this->seedUser(3);
        $jar = $this->newCookieJar();
        $this->login($jar, $viewer['email'], $viewer['password']);

        $res = $this->httpGet($jar, '/audit/index.php');

        $this->assertSame(302, $res['status'], 'a non-Admin must still be redirected away entirely');
        $this->assertStringContainsString('dashboard.php', $res['headers']);
    }

    public function testDefaultOrderingIsIdDescending(): void
    {
        $admin = $this->seedUser(1);
        $marker = $this->uniqueMarker();
        $id1 = $this->insertAuditRow($admin['id'], $marker, 'create', 'Marker-First');
        $id2 = $this->insertAuditRow($admin['id'], $marker, 'create', 'Marker-Second');
        $id3 = $this->insertAuditRow($admin['id'], $marker, 'create', 'Marker-Third');
        $this->assertGreaterThan($id2, $id3);
        $this->assertGreaterThan($id1, $id2);

        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);
        $res = $this->httpGet($jar, '/audit/index.php?entity_type=' . urlencode($marker));

        $this->assertSame(200, $res['status']);
        $posThird = strpos($res['body'], 'Marker-Third');
        $posSecond = strpos($res['body'], 'Marker-Second');
        $posFirst = strpos($res['body'], 'Marker-First');
        $this->assertNotFalse($posThird);
        $this->assertNotFalse($posSecond);
        $this->assertNotFalse($posFirst);
        $this->assertTrue($posThird < $posSecond && $posSecond < $posFirst, 'rows must render most-recent-id first (a.id DESC)');
    }

    public function testEntityTypeFilterOnlyShowsMatchingRows(): void
    {
        $admin = $this->seedUser(1);
        $markerA = $this->uniqueMarker();
        $markerB = $this->uniqueMarker();
        $this->insertAuditRow($admin['id'], $markerA, 'create', 'EntityTypeA-Row');
        $this->insertAuditRow($admin['id'], $markerB, 'create', 'EntityTypeB-Row');

        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);
        $res = $this->httpGet($jar, '/audit/index.php?entity_type=' . urlencode($markerA));

        $this->assertStringContainsString('EntityTypeA-Row', $res['body']);
        $this->assertStringNotContainsString('EntityTypeB-Row', $res['body']);
    }

    public function testActionFilterOnlyShowsMatchingRows(): void
    {
        $admin = $this->seedUser(1);
        $marker = $this->uniqueMarker();
        $this->insertAuditRow($admin['id'], $marker, 'create', 'ActionCreate-Row');
        $this->insertAuditRow($admin['id'], $marker, 'update', 'ActionUpdate-Row');

        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);
        $res = $this->httpGet($jar, '/audit/index.php?entity_type=' . urlencode($marker) . '&action=create');

        $this->assertStringContainsString('ActionCreate-Row', $res['body']);
        $this->assertStringNotContainsString('ActionUpdate-Row', $res['body']);
    }

    public function testActorFilterOnlyShowsMatchingRows(): void
    {
        $admin1 = $this->seedUser(1);
        $admin2 = $this->seedUser(1);
        $marker = $this->uniqueMarker();
        $this->insertAuditRow($admin1['id'], $marker, 'create', 'Actor1-Row');
        $this->insertAuditRow($admin2['id'], $marker, 'create', 'Actor2-Row');

        $jar = $this->newCookieJar();
        $this->login($jar, $admin1['email'], $admin1['password']);
        $res = $this->httpGet($jar, '/audit/index.php?entity_type=' . urlencode($marker) . '&actor=' . $admin1['id']);

        $this->assertStringContainsString('Actor1-Row', $res['body']);
        $this->assertStringNotContainsString('Actor2-Row', $res['body']);
    }

    public function testDateRangeFilterExcludesRowsOutsideRange(): void
    {
        $admin = $this->seedUser(1);
        $marker = $this->uniqueMarker();
        $this->insertAuditRow($admin['id'], $marker, 'create', 'InsideRange-Row', '2024-06-15 10:00:00');
        $this->insertAuditRow($admin['id'], $marker, 'create', 'OutsideRange-Row', '2020-01-01 10:00:00');

        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);
        $res = $this->httpGet($jar, '/audit/index.php?entity_type=' . urlencode($marker) . '&date_from=2024-06-01&date_to=2024-06-30');

        $this->assertStringContainsString('InsideRange-Row', $res['body']);
        $this->assertStringNotContainsString('OutsideRange-Row', $res['body']);
    }

    public function testInvalidActionAndEntityTypeValuesDoNotProduceSqlErrors(): void
    {
        $admin = $this->seedUser(1);
        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);

        $res = $this->httpGet($jar, '/audit/index.php?action=' . urlencode("create' OR '1'='1") . '&entity_type=' . urlencode("'; DROP TABLE audit_log; --"));

        $this->assertSame(200, $res['status'], 'an invalid/malicious filter value must never produce a 500 or crash the page: ' . $res['body']);
        $this->assertStringNotContainsString('SQLSTATE', $res['body']);
        $this->assertStringNotContainsString('Fatal error', $res['body']);

        // The whitelist rejection must not have dropped the table it
        // pretended to target - a real, direct proof that no raw value
        // ever reached SQL, not just an absence-of-error-text check.
        $stillExists = $this->pdo->query("SHOW TABLES LIKE 'audit_log'")->fetchColumn();
        $this->assertNotFalse($stillExists, 'audit_log table must still exist');
    }

    public function testPaginationPage1AndPage2ShowDifferentRows(): void
    {
        $admin = $this->seedUser(1);
        $marker = $this->uniqueMarker();
        for ($i = 1; $i <= 51; $i++) {
            $this->insertAuditRow($admin['id'], $marker, 'create', "PageMarker-{$i}");
        }

        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);

        $page1 = $this->httpGet($jar, '/audit/index.php?entity_type=' . urlencode($marker) . '&page=1');
        $page2 = $this->httpGet($jar, '/audit/index.php?entity_type=' . urlencode($marker) . '&page=2');

        // Row 51 was inserted last (highest id) - a.id DESC puts it first,
        // so it must land on page 1 (the newest 50 of 51 total rows) and
        // never on page 2. Row 1 (lowest id, oldest) is the 51st/last row
        // overall, so it must be the only one pushed onto page 2.
        $this->assertStringContainsString('PageMarker-51', $page1['body']);
        $this->assertStringNotContainsString('PageMarker-1<', $page1['body']);
        $this->assertStringContainsString('PageMarker-1<', $page2['body']);
        $this->assertStringNotContainsString('PageMarker-51', $page2['body']);
    }

    public function testIdOrderingIsDeterministicWhenCreatedAtIsIdentical(): void
    {
        $admin = $this->seedUser(1);
        $marker = $this->uniqueMarker();
        $sameTimestamp = '2024-03-01 09:00:00';
        $idOlder = $this->insertAuditRow($admin['id'], $marker, 'create', 'TieBreak-Older', $sameTimestamp);
        $idNewer = $this->insertAuditRow($admin['id'], $marker, 'create', 'TieBreak-Newer', $sameTimestamp);
        $this->assertGreaterThan($idOlder, $idNewer, 'the second insert must get the higher id despite the identical created_at');

        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);
        $res = $this->httpGet($jar, '/audit/index.php?entity_type=' . urlencode($marker));

        $posNewer = strpos($res['body'], 'TieBreak-Newer');
        $posOlder = strpos($res['body'], 'TieBreak-Older');
        $this->assertNotFalse($posNewer);
        $this->assertNotFalse($posOlder);
        $this->assertLessThan($posOlder, $posNewer, 'with tied created_at, the higher-id row must still render first');
    }

    public function testCustomerDebtEntityTypeRendersTranslatedLabel(): void
    {
        $admin = $this->seedUser(1);
        $this->insertAuditRow($admin['id'], 'customer_debt', 'create', 'TranslatedLabel-Row');

        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);
        $res = $this->httpGet($jar, '/audit/index.php?entity_type=customer_debt');

        $this->assertStringContainsString('TranslatedLabel-Row', $res['body']);
        $this->assertStringContainsString('Customer Debt', $res['body'], 'customer_debt must render its translated label, not the raw entity_type string');
    }

    private function uniqueMarker(): string
    {
        return 'v2b2_' . bin2hex(random_bytes(6));
    }

    private function insertAuditRow(int $userId, string $entityType, string $action, string $name, ?string $createdAt = null): int
    {
        $createdAt = $createdAt ?? date('Y-m-d H:i:s');
        static $entityId = 900000;
        $entityId++;
        $stmt = $this->pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, before_snapshot, after_snapshot, created_at) VALUES (?,?,?,?,NULL,?,?)');
        $stmt->execute([$userId, $action, $entityType, $entityId, json_encode(['name' => $name]), $createdAt]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupAuditIds[] = $id;
        return $id;
    }

    /** @return array{id:int,email:string,password:string} */
    private function seedUser(int $roleId): array
    {
        $email = 'v2b2audit.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,?)');
        $stmt->execute(['V2-B2 Audit Test User', $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupUserIds[] = $id;
        return ['id' => $id, 'email' => $email, 'password' => $password];
    }
}
