<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase K2-A (session lifecycle hardening). Drives the real
// auth/login.php, auth/logout.php and profile.php over real HTTP and
// asserts on the actual Set-Cookie headers the server emits - the same
// thing a browser would act on.
//
// Verified against the pre-K2-A code: four of the six tests fail there
// (login rotation, the planted-id case, the logout Set-Cookie, and the
// password-change rotation), so those pin the fix rather than merely
// describing it. The remaining two - the failed-login case and the
// replay of a pre-logout id - already passed beforehand and are kept as
// no-regression guards: session_destroy() was already removing the
// server-side record, and that must stay true.
//
// Session identity is observed through the response headers and through
// curl's own cookie jar, so nothing here depends on PHP session
// internals or on the test harness being modified.
final class SessionLifecycleTest extends HttpServerTestCase
{
    private array $cleanupUserIds = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupUserIds as $id) {
            $this->pdo->exec("DELETE FROM audit_log WHERE entity_type = 'user' AND entity_id = $id");
            $this->pdo->exec("DELETE FROM users WHERE id = $id");
        }
        parent::tearDown();
    }

    // ---- login rotation ----

    public function testSuccessfulLoginRotatesTheSessionId(): void
    {
        [$email, $password] = $this->seedUser();
        $jar = $this->newCookieJar();

        // Visit the login page first so the server issues a session ID
        // the way a real browser would receive one before submitting.
        $before = $this->httpGet($jar, '/auth/login.php');
        $preLoginId = $this->sessionIdFromJar($jar);
        $this->assertNotSame('', $preLoginId, 'the login page must establish a session id to rotate away from');
        $this->assertSame(200, $before['status']);

        $res = $this->httpPost($jar, '/auth/login.php', ['email' => $email, 'password' => $password]);
        $this->assertSame(302, $res['status'], 'login must still redirect on success: ' . $res['body']);

        $postLoginId = $this->sessionIdFromJar($jar);
        $this->assertNotSame('', $postLoginId);
        $this->assertNotSame(
            $preLoginId,
            $postLoginId,
            'the session id must be rotated on successful login (session fixation defence)'
        );
    }

    public function testAnAttackerSuppliedSessionIdDoesNotSurviveLogin(): void
    {
        [$email, $password] = $this->seedUser();
        $fixedId = 'k2afixedsessionid' . bin2hex(random_bytes(6));

        // Pre-seed the jar with an id the "attacker" chose, exactly as a
        // planted cookie would arrive. PHP's use_strict_mode defaults to
        // 0, so the server adopts it - which is precisely why login must
        // rotate it away.
        $jar = $this->newCookieJar();
        $this->seedCookieJar($jar, $fixedId);

        $res = $this->httpPost($jar, '/auth/login.php', ['email' => $email, 'password' => $password]);
        $this->assertSame(302, $res['status'], 'login must succeed: ' . $res['body']);

        $afterId = $this->sessionIdFromJar($jar);
        $this->assertNotSame(
            $fixedId,
            $afterId,
            'an attacker-supplied session id must NOT become an authenticated session'
        );

        // And the planted id itself must not be usable for access.
        $attackerJar = $this->newCookieJar();
        $this->seedCookieJar($attackerJar, $fixedId);
        $probe = $this->httpGet($attackerJar, '/dashboard.php');
        $this->assertSame(
            302,
            $probe['status'],
            'the planted session id must not grant access to an authenticated page'
        );
    }

    public function testFailedLoginDoesNotEstablishAnAuthenticatedSession(): void
    {
        [$email] = $this->seedUser();
        $jar = $this->newCookieJar();

        $res = $this->httpPost($jar, '/auth/login.php', ['email' => $email, 'password' => 'wrong-password']);
        $this->assertSame(200, $res['status'], 'a failed login re-renders the form rather than redirecting');

        $probe = $this->httpGet($jar, '/dashboard.php');
        $this->assertSame(302, $probe['status'], 'a failed login must leave the session unauthenticated');
    }

    // ---- logout ----

    public function testLogoutExpiresTheSessionCookie(): void
    {
        [$email, $password] = $this->seedUser();
        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);

        $res = $this->httpGet($jar, '/auth/logout.php');
        $this->assertSame(302, $res['status'], 'logout must still redirect');

        $this->assertMatchesRegularExpression(
            '/^Set-Cookie:\s*PHPSESSID=/mi',
            $res['headers'],
            'logout must send a Set-Cookie for the session cookie so the browser clears it'
        );
        // An expiry in the past (or Max-Age=0) is what actually removes
        // the cookie; assert the header carries one rather than just
        // re-setting the cookie.
        $this->assertMatchesRegularExpression(
            '/^Set-Cookie:\s*PHPSESSID=[^;]*;[^\n]*(expires=|Max-Age=0)/mi',
            $res['headers'],
            'the logout Set-Cookie must expire the cookie, not merely rewrite it'
        );
    }

    public function testLogoutInvalidatesTheOldAuthenticatedSession(): void
    {
        [$email, $password] = $this->seedUser();
        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);

        $authenticated = $this->httpGet($jar, '/dashboard.php');
        $this->assertSame(200, $authenticated['status'], 'precondition: the session is authenticated before logout');
        $sessionId = $this->sessionIdFromJar($jar);
        $this->assertNotSame('', $sessionId);

        $this->httpGet($jar, '/auth/logout.php');

        // Replay the captured id from a completely separate jar - this is
        // what a stolen/retained cookie would do. It must no longer work.
        $replayJar = $this->newCookieJar();
        $this->seedCookieJar($replayJar, $sessionId);
        $replay = $this->httpGet($replayJar, '/dashboard.php');
        $this->assertSame(
            302,
            $replay['status'],
            'the pre-logout session id must not access an authenticated page after logout'
        );
    }

    // ---- password change ----

    public function testPasswordChangeRotatesTheSessionIdAndKeepsTheUserSignedIn(): void
    {
        [$email, $password, $userId] = $this->seedUser();
        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);

        $beforeId = $this->sessionIdFromJar($jar);
        $this->assertNotSame('', $beforeId);

        $page = $this->httpGet($jar, '/profile.php');
        $this->assertSame(200, $page['status']);
        $token = $this->extractCsrfToken($page['body']);
        $this->assertNotSame('', $token, 'profile page must render a CSRF token');

        $newPassword = 'K2ANewPass456!';
        $res = $this->httpPost($jar, '/profile.php', [
            'csrf_token' => $token,
            'update_password' => '1',
            'current_password' => $password,
            'new_password' => $newPassword,
            'confirm_password' => $newPassword,
        ]);
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('Password changed', $res['body'], 'the password change must succeed');

        $afterId = $this->sessionIdFromJar($jar);
        $this->assertNotSame('', $afterId);
        $this->assertNotSame($beforeId, $afterId, 'a successful password change must rotate the session id');

        // The user must remain signed in on the rotated session.
        $stillIn = $this->httpGet($jar, '/dashboard.php');
        $this->assertSame(200, $stillIn['status'], 'the user must stay authenticated after changing their password');

        // And the change must actually have been persisted.
        $stmt = $this->pdo->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $this->assertTrue(
            password_verify($newPassword, (string) $stmt->fetchColumn()),
            'the new password must be stored'
        );
    }

    // ---- helpers ----

    /** @return array{0:string,1:string,2:int} email, password, id */
    private function seedUser(): array
    {
        $email = 'k2a.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'K2ATestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['K2A Session Test User', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupUserIds[] = $id;
        return [$email, $password, $id];
    }

    // Reads the current PHPSESSID out of curl's Netscape-format cookie
    // jar. Returns '' when no session cookie is present.
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

    // Writes a chosen PHPSESSID into a jar so the next request presents
    // it - the test-side equivalent of a planted cookie.
    private function seedCookieJar(string $jar, string $sessionId): void
    {
        file_put_contents(
            $jar,
            "# Netscape HTTP Cookie File\n"
            . implode("\t", ['127.0.0.1', 'FALSE', '/', 'FALSE', '0', 'PHPSESSID', $sessionId]) . "\n"
        );
    }
}
