<?php
declare(strict_types=1);

namespace Tests\Http;

// V2-B3 - Account Deactivation. Drives the real auth/login.php,
// includes/auth_check.php (via any authenticated page), and
// user/index.php's new toggle_active action over real HTTP - RBAC/
// session/timing concerns here are page-controller/session-lifecycle
// behavior, the same reason tests/Http/PrivilegeFreshnessTest.php and
// tests/Http/LoginThrottleTest.php already exist as HTTP tests rather
// than plain function calls.
final class AccountDeactivationHttpTest extends HttpServerTestCase
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

    // ---- session freshness ----

    public function testDeactivatingALiveSessionEndsItOnItsNextRequest(): void
    {
        $user = $this->seedUser(2);
        $jar = $this->newCookieJar();
        $this->login($jar, $user['email'], $user['password']);
        $this->assertSame(200, $this->httpGet($jar, '/dashboard.php')['status'], 'precondition: normal access');

        $this->setActive($user['id'], 0);

        // Same session, no logout, no re-login - and a full teardown
        // (redirect to login.php), not merely a privilege downgrade the
        // way a role demotion leaves the session alive as a lesser role.
        $res = $this->httpGet($jar, '/dashboard.php');
        $this->assertSame(302, $res['status']);
        $this->assertStringContainsString('login.php', $res['headers']);

        // And re-fetching any authenticated page must keep bouncing to
        // login, not just this one time.
        $this->assertSame(302, $this->httpGet($jar, '/dashboard.php')['status']);
    }

    // ---- login rejection ----

    public function testAnInactiveAccountCannotLogIn(): void
    {
        $user = $this->seedUser(2, 0);

        $res = $this->httpPost($this->newCookieJar(), '/auth/login.php', [
            'email' => $user['email'], 'password' => $user['password'],
        ]);

        $this->assertSame(200, $res['status'], 'a rejected login must still render the login page, not redirect');
        $this->assertStringNotContainsString('Location:', $res['headers'], 'a deactivated account must never be redirected to dashboard.php');
    }

    public function testInactiveLoginUsesTheSameGenericMessageAsAWrongPassword(): void
    {
        $active = $this->seedUser(2);
        $inactive = $this->seedUser(2, 0);

        $wrongPasswordRes = $this->httpPost($this->newCookieJar(), '/auth/login.php', [
            'email' => $active['email'], 'password' => 'not-the-real-password',
        ]);
        $inactiveRes = $this->httpPost($this->newCookieJar(), '/auth/login.php', [
            'email' => $inactive['email'], 'password' => $inactive['password'],
        ]);

        $this->assertSame($wrongPasswordRes['status'], $inactiveRes['status']);
        $this->assertSame(
            $this->extractLoginError($wrongPasswordRes['body']),
            $this->extractLoginError($inactiveRes['body']),
            'a deactivated account must be indistinguishable from a wrong password - same generic error text'
        );
    }

    // ---- timing oracle (precedent: LoginThrottleTest's own timing-oracle test) ----

    public function testInactiveAccountLoginCostsComparablePasswordWorkToAWrongPassword(): void
    {
        $active = $this->seedUser(2);
        $inactive = $this->seedUser(2, 0);

        $wrongPassword = $this->medianFailureSeconds($active['email'], 'not-the-real-password');
        $deactivated = $this->medianFailureSeconds($inactive['email'], $inactive['password']);

        $this->assertGreaterThan(0.0, $wrongPassword);
        $this->assertGreaterThan(0.0, $deactivated);

        // Same deliberately loose bound as LoginThrottleTest's own
        // K2-C timing-oracle test: both paths run one real bcrypt verify
        // before the is_active check ever runs, so the ratio should sit
        // near 1. Anything under 5x is noise on a busy machine.
        $ratio = max($wrongPassword, $deactivated) / min($wrongPassword, $deactivated);
        $this->assertLessThan(
            5.0,
            $ratio,
            sprintf(
                'a wrong password and a deactivated-but-correct password must cost comparable work (wrong-password %.4fs vs deactivated %.4fs, ratio %.1fx)',
                $wrongPassword,
                $deactivated,
                $ratio
            )
        );
    }

    // ---- Admin UI: self-deactivation ----

    public function testSelfDeactivationIsRejected(): void
    {
        $admin = $this->seedUser(1);
        $this->seedUser(1); // a second active Admin, so this test isolates the self-guard from the last-Admin guard
        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);
        $token = $this->csrfToken($jar);
        $auditCountBefore = $this->auditCount($admin['id']);

        $res = $this->httpPost($jar, '/user/index.php', [
            'csrf_token' => $token, 'action' => 'toggle_active', 'id' => (string) $admin['id'], 'new_state' => '0',
        ]);

        $this->assertSame(200, $res['status'], 'a rejected self-action must re-render the page, not redirect');
        $this->assertSame(1, $this->activeOf($admin['id']), 'the account must remain active');
        $this->assertSame($auditCountBefore, $this->auditCount($admin['id']), 'a rejected self-deactivation must not create an audit row');
    }

    // ---- Admin UI: last active Admin ----

    public function testLastActiveAdminCannotBeDeactivated(): void
    {
        $admin = $this->seedUser(1);
        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);
        $token = $this->csrfToken($jar);
        $auditCountBefore = $this->auditCount($admin['id']);

        // The only Admin in the table - the sole way this specific guard
        // is reachable is that Admin acting on themselves (see
        // user/index.php's own comment on the guard ordering: a distinct
        // actor/target pair always counts at least two active Admins).
        $res = $this->httpPost($jar, '/user/index.php', [
            'csrf_token' => $token, 'action' => 'toggle_active', 'id' => (string) $admin['id'], 'new_state' => '0',
        ]);

        $this->assertSame(200, $res['status']);
        $this->assertSame(1, $this->activeOf($admin['id']), 'the last active Admin must remain active');
        $this->assertSame($auditCountBefore, $this->auditCount($admin['id']), 'a rejected last-Admin attempt must not create an audit row');
    }

    public function testWhenTwoActiveAdminsExistOneCanBeDeactivated(): void
    {
        $adminA = $this->seedUser(1);
        $adminB = $this->seedUser(1);
        $jar = $this->newCookieJar();
        $this->login($jar, $adminA['email'], $adminA['password']);
        $token = $this->csrfToken($jar);

        $res = $this->httpPost($jar, '/user/index.php', [
            'csrf_token' => $token, 'action' => 'toggle_active', 'id' => (string) $adminB['id'], 'new_state' => '0',
        ]);

        $this->assertSame(302, $res['status']);
        $this->assertSame(0, $this->activeOf($adminB['id']));
        $this->assertSame(1, $this->activeOf($adminA['id']), 'the acting Admin must remain untouched and active');
    }

    // ---- reactivation ----

    public function testReactivationRestoresLoginAndAccess(): void
    {
        $admin = $this->seedUser(1);
        $this->seedUser(1); // a second Admin so deactivating the target below is not the last-Admin case
        $target = $this->seedUser(2);
        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);
        $token = $this->csrfToken($jar);

        $this->httpPost($jar, '/user/index.php', [
            'csrf_token' => $token, 'action' => 'toggle_active', 'id' => (string) $target['id'], 'new_state' => '0',
        ]);
        $this->assertSame(0, $this->activeOf($target['id']), 'precondition: deactivated');
        $failedLogin = $this->httpPost($this->newCookieJar(), '/auth/login.php', [
            'email' => $target['email'], 'password' => $target['password'],
        ]);
        $this->assertSame(200, $failedLogin['status'], 'precondition: login must fail while inactive');

        $token2 = $this->csrfToken($jar);
        $mustChangeBefore = $this->mustChangePasswordOf($target['id']);
        $res = $this->httpPost($jar, '/user/index.php', [
            'csrf_token' => $token2, 'action' => 'toggle_active', 'id' => (string) $target['id'], 'new_state' => '1',
        ]);
        $this->assertSame(302, $res['status']);
        $this->assertSame(1, $this->activeOf($target['id']));
        $this->assertSame($mustChangeBefore, $this->mustChangePasswordOf($target['id']), 'reactivation must never silently change must_change_password');

        $freshJar = $this->newCookieJar();
        $this->login($freshJar, $target['email'], $target['password']);
        $this->assertSame(200, $this->httpGet($freshJar, '/dashboard.php')['status'], 'login must work again after reactivation');
    }

    // ---- audit ----

    public function testSuccessfulTransitionWritesExactlyOneAuditEvent(): void
    {
        $admin = $this->seedUser(1);
        $target = $this->seedUser(2);
        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);
        $token = $this->csrfToken($jar);
        $before = $this->auditCount($target['id']);

        $this->httpPost($jar, '/user/index.php', [
            'csrf_token' => $token, 'action' => 'toggle_active', 'id' => (string) $target['id'], 'new_state' => '0',
        ]);

        $this->assertSame($before + 1, $this->auditCount($target['id']), 'exactly one new audit_log row must be created for a successful transition');
    }

    public function testAuditSnapshotsContainCorrectIsActiveValues(): void
    {
        $admin = $this->seedUser(1);
        $target = $this->seedUser(2);
        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);
        $token = $this->csrfToken($jar);

        $this->httpPost($jar, '/user/index.php', [
            'csrf_token' => $token, 'action' => 'toggle_active', 'id' => (string) $target['id'], 'new_state' => '0',
        ]);

        $row = $this->latestAuditRow($target['id']);
        $this->assertSame('update', $row['action']);
        $this->assertSame('user', $row['entity_type']);
        $this->assertSame($target['id'], (int) $row['entity_id']);
        $this->assertSame($admin['id'], (int) $row['user_id']);

        $before = json_decode($row['before_snapshot'], true);
        $after = json_decode($row['after_snapshot'], true);
        $this->assertSame(1, $before['is_active']);
        $this->assertSame(0, $after['is_active']);
        $this->assertArrayNotHasKey('password', $before);
        $this->assertArrayNotHasKey('password', $after);
    }

    public function testRepeatedSameStateRequestsDoNotCreateFalseTransitionAuditEvents(): void
    {
        $admin = $this->seedUser(1);
        $target = $this->seedUser(2);
        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);

        $token1 = $this->csrfToken($jar);
        $this->httpPost($jar, '/user/index.php', [
            'csrf_token' => $token1, 'action' => 'toggle_active', 'id' => (string) $target['id'], 'new_state' => '0',
        ]);
        $this->assertSame(0, $this->activeOf($target['id']), 'precondition: deactivated');
        $countAfterFirst = $this->auditCount($target['id']);

        // The exact same request again, while already inactive.
        $token2 = $this->csrfToken($jar);
        $res = $this->httpPost($jar, '/user/index.php', [
            'csrf_token' => $token2, 'action' => 'toggle_active', 'id' => (string) $target['id'], 'new_state' => '0',
        ]);

        $this->assertSame(302, $res['status'], 'a same-state resubmission is a harmless no-op, not an error');
        $this->assertSame(0, $this->activeOf($target['id']));
        $this->assertSame($countAfterFirst, $this->auditCount($target['id']), 'a same-state resubmission must not create a second audit row');
    }

    // ---- helpers ----

    /** @return array{id:int,email:string,password:string} */
    private function seedUser(int $roleId, int $active = 1): array
    {
        $email = 'v2b3.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'V2B3TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id, is_active) VALUES (?,?,?,?,?)');
        $stmt->execute(['V2B3 Deactivation Test User', $email, password_hash($password, PASSWORD_DEFAULT), $roleId, $active]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupUserIds[] = $id;
        $this->cleanupEmails[] = $email;
        return ['id' => $id, 'email' => $email, 'password' => $password];
    }

    private function setActive(int $userId, int $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
        $stmt->execute([$active, $userId]);
    }

    private function activeOf(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT is_active FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    private function mustChangePasswordOf(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT must_change_password FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    private function auditCount(int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'user' AND entity_id = ?");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    private function latestAuditRow(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'user' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $this->assertIsArray($row, "expected an audit_log row for user#$userId");
        return $row;
    }

    private function csrfToken(string $jar): string
    {
        $page = $this->httpGet($jar, '/user/index.php');
        $this->assertSame(200, $page['status'], 'expected the acting session to already be an Admin');
        return $this->extractCsrfToken($page['body']);
    }

    private function extractLoginError(string $body): string
    {
        if (!preg_match('/<div class="alert alert-danger py-2">([^<]*)<\/div>/', $body, $m)) {
            $this->fail('Could not find a login error message in the response body.');
        }
        return $m[1];
    }

    private function medianFailureSeconds(string $email, string $password): float
    {
        $samples = [];
        for ($i = 0; $i < 3; $i++) {
            $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE email = ?');
            $stmt->execute([strtolower($email)]);
            $start = microtime(true);
            $this->httpPost($this->newCookieJar(), '/auth/login.php', ['email' => $email, 'password' => $password]);
            $samples[] = microtime(true) - $start;
        }
        sort($samples);
        return $samples[1];
    }
}
