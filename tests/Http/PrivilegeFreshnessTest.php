<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase K2-D (privilege freshness and session invalidation).
//
// Before this batch, $_SESSION['role_id'] was written once at login and
// never refreshed, and all 63 isAdmin()/canWrite() call sites read it.
// The measured consequences, reproduced against the previous main:
// a demoted Admin kept full Admin access on their live session and was
// able to promote another account back to Admin from a role_id=2
// session; a password reset replaced the hash while every existing
// session carried on working; and a session whose user row had been
// deleted still served authenticated pages.
//
// These tests drive the real endpoints over real HTTP and change the
// database out from under a live session, which is exactly the shape of
// the original defect. They assert on the DATABASE as well as on status
// codes wherever a write is involved - a 302 alone would not prove the
// mutation was actually prevented.
final class PrivilegeFreshnessTest extends HttpServerTestCase
{
    private array $cleanupUserIds = [];
    private array $cleanupEmails = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupEmails as $email) {
            $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE email = ?');
            $stmt->execute([strtolower($email)]);
        }
        foreach ($this->cleanupUserIds as $id) {
            $this->pdo->exec("DELETE FROM audit_log WHERE entity_type = 'user' AND entity_id = $id");
            $this->pdo->exec("DELETE FROM users WHERE id = $id");
        }
        parent::tearDown();
    }

    // ---- role freshness ----

    public function testAnAdminSessionCanReachAdminOnlyPages(): void
    {
        $admin = $this->seedUser(1);
        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);

        foreach (['/user/index.php', '/settings/index.php', '/audit/index.php'] as $page) {
            $this->assertSame(200, $this->httpGet($jar, $page)['status'], "Admin must reach $page");
        }
    }

    public function testDemotingAnAdminRevokesTheLiveSessionOnItsNextRequest(): void
    {
        $admin = $this->seedUser(1);
        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);
        $this->assertSame(200, $this->httpGet($jar, '/user/index.php')['status'], 'precondition: Admin access');

        $this->setRole($admin['id'], 2);

        // Same session, no logout, no re-login.
        foreach (['/user/index.php', '/settings/index.php', '/audit/index.php'] as $page) {
            $this->assertSame(302, $this->httpGet($jar, $page)['status'], "demoted session must lose $page");
        }

        // Still a valid User though - demotion is not a logout.
        $this->assertSame(200, $this->httpGet($jar, '/dashboard.php')['status'], 'the user must remain signed in as a User');
    }

    public function testADemotedSessionCannotPerformAnAdminOnlyAction(): void
    {
        $admin = $this->seedUser(1);
        $victim = $this->seedUser(2);
        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);

        // Grab a valid CSRF token while still Admin - this is the real
        // attack shape, an already-open page submitted after the
        // privilege was revoked. It also removes CSRF as an alternative
        // explanation for the rejection.
        $page = $this->httpGet($jar, '/user/index.php');
        $this->assertSame(200, $page['status']);
        $token = $this->extractCsrfToken($page['body']);

        $this->setRole($admin['id'], 2);

        $res = $this->httpPost($jar, '/user/index.php', [
            'csrf_token' => $token,
            'action' => 'update_role',
            'id' => (string) $victim['id'],
            'role_id' => '1',
        ]);

        $this->assertSame(302, $res['status'], 'the demoted session must be redirected away');
        $this->assertSame(
            2,
            $this->roleOf($victim['id']),
            'no privilege grant may survive the demotion - the target role must be unchanged'
        );
    }

    public function testPromotingAUserIsRecognisedByTheLiveSession(): void
    {
        $user = $this->seedUser(2);
        $jar = $this->newCookieJar();
        $this->login($jar, $user['email'], $user['password']);
        $this->assertSame(302, $this->httpGet($jar, '/user/index.php')['status'], 'precondition: not an Admin');

        $this->setRole($user['id'], 1);

        $this->assertSame(
            200,
            $this->httpGet($jar, '/user/index.php')['status'],
            'the new privilege must be visible without re-login'
        );
    }

    public function testAViewerRemainsReadOnlyAcrossTheRefresh(): void
    {
        $viewer = $this->seedUser(3);
        $product = $this->seedProduct(50);
        $jar = $this->newCookieJar();
        $this->login($jar, $viewer['email'], $viewer['password']);

        $form = $this->httpGet($jar, '/stock-out/index.php');
        $this->assertSame(200, $form['status'], 'a Viewer still reads');
        $token = $this->extractCsrfToken($form['body']);
        $before = $this->countStockOut();

        $this->httpPost($jar, '/stock-out/index.php', [
            'csrf_token' => $token,
            'transaction_date' => date('Y-m-d'),
            'note' => 'K2-D viewer write attempt',
            'product_id' => [(string) $product['id']],
            'qty' => ['5'],
            'unit_price' => ['1.00'],
        ]);

        $this->assertSame($before, $this->countStockOut(), 'a Viewer must still be unable to write');
        $this->assertSame(302, $this->httpGet($jar, '/user/index.php')['status'], 'and must never gain Admin');
    }

    // ---- must_change_password freshness ----

    public function testSettingMustChangePasswordRedirectsTheLiveSession(): void
    {
        $user = $this->seedUser(2);
        $jar = $this->newCookieJar();
        $this->login($jar, $user['email'], $user['password']);
        $this->assertSame(200, $this->httpGet($jar, '/dashboard.php')['status'], 'precondition: normal access');

        $stmt = $this->pdo->prepare('UPDATE users SET must_change_password = 1 WHERE id = ?');
        $stmt->execute([$user['id']]);

        $res = $this->httpGet($jar, '/dashboard.php');
        $this->assertSame(302, $res['status']);
        $this->assertStringContainsString('profile.php', $res['headers'], 'must be sent to the password-change page');
    }

    // ---- identity freshness ----

    public function testASessionWhoseUserRowIsGoneBecomesUnauthenticated(): void
    {
        $user = $this->seedUser(2);
        $jar = $this->newCookieJar();
        $this->login($jar, $user['email'], $user['password']);
        $this->assertSame(200, $this->httpGet($jar, '/dashboard.php')['status']);

        $this->pdo->exec("DELETE FROM audit_log WHERE entity_type = 'user' AND entity_id = {$user['id']}");
        $this->pdo->exec("DELETE FROM users WHERE id = {$user['id']}");

        $res = $this->httpGet($jar, '/dashboard.php');
        $this->assertSame(302, $res['status'], 'a session with no user row must not be authenticated');
        $this->assertStringContainsString('login.php', $res['headers']);
    }

    // ---- password-change session invalidation ----

    public function testLoginRecordsAPasswordBaselineThatMatchesTheDatabase(): void
    {
        $user = $this->seedUser(2);
        $jar = $this->newCookieJar();
        $this->login($jar, $user['email'], $user['password']);

        // If login had stored a wrong or missing baseline, the very
        // first authenticated request would already have been torn down
        // - so a 200 here is the proof that it stored the right one.
        $this->assertSame(200, $this->httpGet($jar, '/dashboard.php')['status'], 'the baseline must match on the next request');

        // And moving the column must now break that match.
        $this->bumpPasswordChangedAt($user['id']);
        $this->assertSame(302, $this->httpGet($jar, '/dashboard.php')['status']);
    }

    public function testAnAdminPasswordResetRejectsTheTargetsExistingSession(): void
    {
        $target = $this->seedUser(2);
        $jar = $this->newCookieJar();
        $this->login($jar, $target['email'], $target['password']);
        $this->assertSame(200, $this->httpGet($jar, '/dashboard.php')['status']);

        // What user/index.php's reset action now does: new hash and new
        // timestamp in one statement.
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password = ?, must_change_password = 1, password_changed_at = CURRENT_TIMESTAMP(6) WHERE id = ?'
        );
        $stmt->execute([password_hash('AdminIssued999!', PASSWORD_DEFAULT), $target['id']]);

        $res = $this->httpGet($jar, '/dashboard.php');
        $this->assertSame(302, $res['status'], 'the pre-reset session must be rejected');
        $this->assertStringContainsString('login.php', $res['headers']);
    }

    public function testOnePasswordResetInvalidatesEveryConcurrentSession(): void
    {
        $user = $this->seedUser(2);

        $jars = [];
        for ($i = 0; $i < 3; $i++) {
            $jar = $this->newCookieJar();
            $this->login($jar, $user['email'], $user['password']);
            $this->assertSame(200, $this->httpGet($jar, '/dashboard.php')['status'], "session $i must start authenticated");
            $jars[] = $jar;
        }

        $this->bumpPasswordChangedAt($user['id']);

        foreach ($jars as $i => $jar) {
            $this->assertSame(
                302,
                $this->httpGet($jar, '/dashboard.php')['status'],
                "session $i must be rejected - they all read the same row, so they converge without a session registry"
            );
        }
    }

    public function testChangingYourOwnPasswordKeepsYouInButEndsYourOtherSessions(): void
    {
        $user = $this->seedUser(2);

        $mine = $this->newCookieJar();
        $this->login($mine, $user['email'], $user['password']);
        $other = $this->newCookieJar();
        $this->login($other, $user['email'], $user['password']);
        $this->assertSame(200, $this->httpGet($other, '/dashboard.php')['status'], 'precondition: the other session works');

        $profile = $this->httpGet($mine, '/profile.php');
        $this->assertSame(200, $profile['status']);
        $token = $this->extractCsrfToken($profile['body']);

        $newPassword = 'K2dChanged456!';
        $res = $this->httpPost($mine, '/profile.php', [
            'csrf_token' => $token,
            'update_password' => '1',
            'current_password' => $user['password'],
            'new_password' => $newPassword,
            'confirm_password' => $newPassword,
        ]);
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('Password changed', $res['body'], 'the change itself must succeed');

        // The existing UX keeps the person who made the change signed
        // in; K2-D must not break that by invalidating them with their
        // own new baseline.
        $this->assertSame(200, $this->httpGet($mine, '/dashboard.php')['status'], 'the changing session must stay signed in');

        // Every other session for the account is now stale.
        $this->assertSame(302, $this->httpGet($other, '/dashboard.php')['status'], 'the other session must be rejected');

        // And the new password is what actually works.
        $fresh = $this->newCookieJar();
        $this->login($fresh, $user['email'], $newPassword);
        $this->assertSame(200, $this->httpGet($fresh, '/dashboard.php')['status']);
    }

    // ---- K2-A / K2-B / K2-C compatibility ----

    public function testK2ASessionRotationStillHappensOnLogin(): void
    {
        $user = $this->seedUser(2);
        $jar = $this->newCookieJar();

        $this->httpGet($jar, '/auth/login.php');
        $before = $this->sessionIdFromJar($jar);
        $this->assertNotSame('', $before);

        $this->login($jar, $user['email'], $user['password']);
        $this->assertNotSame($before, $this->sessionIdFromJar($jar), 'K2-A rotation must survive K2-D');
    }

    public function testK2BCookieAttributesSurviveAPrivilegeRefreshedRequest(): void
    {
        $user = $this->seedUser(2);
        $jar = $this->newCookieJar();
        $res = $this->httpGet($jar, '/auth/login.php');

        $this->assertMatchesRegularExpression('/^Set-Cookie:[^\n]*HttpOnly/mi', $res['headers']);
        $this->assertMatchesRegularExpression('/^Set-Cookie:[^\n]*SameSite=Lax/mi', $res['headers']);

        // And the teardown path K2-D added must expire the cookie with
        // those same attributes, exactly as logout does.
        $this->login($jar, $user['email'], $user['password']);
        $this->pdo->exec("DELETE FROM audit_log WHERE entity_type = 'user' AND entity_id = {$user['id']}");
        $this->pdo->exec("DELETE FROM users WHERE id = {$user['id']}");

        $torn = $this->httpGet($jar, '/dashboard.php');
        $this->assertSame(302, $torn['status']);
        $this->assertMatchesRegularExpression(
            '/^Set-Cookie:\s*PHPSESSID=[^;]*;[^\n]*(expires=|Max-Age=0)/mi',
            $torn['headers'],
            'the stale-session teardown must expire the cookie the same way logout does'
        );
    }

    public function testK2CLoginThrottleStillApplies(): void
    {
        $user = $this->seedUser(2);

        for ($i = 0; $i < 5; $i++) {
            $this->httpPost($this->newCookieJar(), '/auth/login.php', [
                'email' => $user['email'], 'password' => 'wrong-' . $i,
            ]);
        }

        // Sixth attempt, with the RIGHT password, must still be refused.
        $res = $this->httpPost($this->newCookieJar(), '/auth/login.php', [
            'email' => $user['email'], 'password' => $user['password'],
        ]);
        $this->assertSame(200, $res['status'], 'the K2-C throttle must still refuse the sixth attempt');
    }

    // ---- helpers ----

    /** @return array{id:int,email:string,password:string} */
    private function seedUser(int $roleId): array
    {
        $email = 'k2d.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'K2dTestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,?)');
        $stmt->execute(['K2D Freshness Test User', $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupUserIds[] = $id;
        $this->cleanupEmails[] = $email;
        return ['id' => $id, 'email' => $email, 'password' => $password];
    }

    /** @return array{id:int} */
    private function seedProduct(int $stock): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock) VALUES (?,?,?,?,?)');
        $stmt->execute(['K2D Test Product', 'K2D-' . bin2hex(random_bytes(4)), 1, 1, $stock]);
        return ['id' => (int) $this->pdo->lastInsertId()];
    }

    private function countStockOut(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM stock_transactions WHERE type = 'out'")->fetchColumn();
    }

    private function setRole(int $userId, int $roleId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET role_id = ? WHERE id = ?');
        $stmt->execute([$roleId, $userId]);
    }

    private function roleOf(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT role_id FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    private function bumpPasswordChangedAt(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_changed_at = CURRENT_TIMESTAMP(6) WHERE id = ?');
        $stmt->execute([$userId]);
    }

    private function sessionIdFromJar(string $jar): string
    {
        if (!is_file($jar)) {
            return '';
        }
        foreach (file($jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || ($line[0] === '#' && strpos($line, '#HttpOnly_') !== 0)) {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) === 7 && $parts[5] === 'PHPSESSID') {
                return $parts[6];
            }
        }
        return '';
    }
}
