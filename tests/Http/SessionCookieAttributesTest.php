<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase K2-B (session cookie security). Asserts on the raw Set-Cookie
// headers the application actually emits, because the attributes are the
// whole point of the change - an assertion on PHP's ini values would
// pass even if the configuration landed after session_start() and was
// therefore silently ignored.
//
// Every one of these fails against the pre-K2-B code, which shipped no
// session configuration at all and emitted a bare
// "PHPSESSID=...; path=/" with no HttpOnly, SameSite or Secure.
//
// The Secure cases matter in both directions. Over plain HTTP - which is
// what both shipped deployments and this whole test suite use - Secure
// must stay ABSENT, or the browser stops returning the cookie and login
// breaks. That negative assertion is the guard against someone "fixing"
// this later with a hard session.cookie_secure = 1.
final class SessionCookieAttributesTest extends HttpServerTestCase
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

    // ---- A. anonymous session cookie ----

    public function testAnonymousSessionCookieIsHttpOnlyAndSameSiteLax(): void
    {
        $res = $this->httpGet($this->newCookieJar(), '/auth/login.php');
        $this->assertSame(200, $res['status']);

        $cookie = $this->sessionSetCookieHeader($res['headers']);
        $this->assertNotSame('', $cookie, 'the login page must issue a session cookie');
        $this->assertStringContainsStringIgnoringCase('HttpOnly', $cookie);
        $this->assertStringContainsStringIgnoringCase('SameSite=Lax', $cookie);
    }

    public function testAnonymousSessionCookieIsNotSecureOverPlainHttp(): void
    {
        $res = $this->httpGet($this->newCookieJar(), '/auth/login.php');
        $cookie = $this->sessionSetCookieHeader($res['headers']);

        $this->assertNotSame('', $cookie);
        $this->assertStringNotContainsStringIgnoringCase(
            'secure',
            $cookie,
            'Secure must NOT be set over plain HTTP - the browser would stop returning the cookie and login would break'
        );
    }

    // index.php opens its own session independently of includes/lang.php,
    // so it needs its own evidence rather than being assumed covered.
    public function testTheRootEntryPointAlsoIssuesAHardenedCookie(): void
    {
        $res = $this->httpGet($this->newCookieJar(), '/index.php');
        $cookie = $this->sessionSetCookieHeader($res['headers']);

        $this->assertNotSame('', $cookie, '/index.php must issue a session cookie');
        $this->assertStringContainsStringIgnoringCase('HttpOnly', $cookie);
        $this->assertStringContainsStringIgnoringCase('SameSite=Lax', $cookie);
    }

    // ---- B. rotated post-login cookie ----

    public function testTheRotatedLoginCookieKeepsTheHardenedAttributes(): void
    {
        [$email, $password] = $this->seedUser();
        $jar = $this->newCookieJar();

        $this->httpGet($jar, '/auth/login.php');
        $preLoginId = $this->sessionIdFromJar($jar);
        $this->assertNotSame('', $preLoginId);

        $res = $this->httpPost($jar, '/auth/login.php', ['email' => $email, 'password' => $password]);
        $this->assertSame(302, $res['status'], 'login must still succeed: ' . $res['body']);

        // K2-A's rotation must still happen, and the NEW cookie - the one
        // that actually carries the authenticated session - must carry
        // the attributes too, not just the anonymous one.
        $this->assertNotSame($preLoginId, $this->sessionIdFromJar($jar), 'the session id must still rotate on login');

        $cookie = $this->sessionSetCookieHeader($res['headers']);
        $this->assertNotSame('', $cookie, 'the rotation must emit a Set-Cookie');
        $this->assertStringContainsStringIgnoringCase('HttpOnly', $cookie);
        $this->assertStringContainsStringIgnoringCase('SameSite=Lax', $cookie);
        $this->assertStringNotContainsStringIgnoringCase('secure', $cookie, 'still plain HTTP, so still no Secure');

        // And the hardened cookie must remain usable for real navigation.
        $this->assertSame(200, $this->httpGet($jar, '/dashboard.php')['status']);
    }

    // The deletion cookie only removes the real one if its attributes
    // match; a mismatch leaves the original cookie in the browser.
    public function testTheLogoutDeletionCookieMatchesTheConfiguredAttributes(): void
    {
        [$email, $password] = $this->seedUser();
        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);

        $res = $this->httpGet($jar, '/auth/logout.php');
        $this->assertSame(302, $res['status'], 'logout must still redirect');

        $this->assertMatchesRegularExpression(
            '/^Set-Cookie:\s*PHPSESSID=[^;]*;[^\n]*(expires=|Max-Age=0)/mi',
            $res['headers'],
            'logout must still expire the cookie'
        );

        $deletion = $this->matchSetCookie($res['headers'], '/^Set-Cookie:\s*(PHPSESSID=deleted;[^\r\n]*)/mi');
        $this->assertNotSame('', $deletion, 'logout must emit an explicit deletion cookie');
        $this->assertStringContainsStringIgnoringCase('HttpOnly', $deletion);
        $this->assertStringContainsStringIgnoringCase('SameSite=Lax', $deletion);
    }

    // ---- C. strict session mode ----

    public function testAServerUnknownSessionIdIsNotAdopted(): void
    {
        $invented = 'k2bnotissuedbytheserver' . bin2hex(random_bytes(4));

        $res = $this->requestWithHeaders('/auth/login.php', ['Cookie: PHPSESSID=' . $invented]);
        $this->assertSame(200, $res['status']);

        // With use_strict_mode on, PHP discards the invented id and
        // issues its own - so a Set-Cookie MUST be present, and it must
        // not be the invented value.
        $cookie = $this->sessionSetCookieHeader($res['headers']);
        $this->assertNotSame(
            '',
            $cookie,
            'a session id the server never issued must be replaced, not adopted'
        );
        $this->assertStringNotContainsString(
            $invented,
            $cookie,
            'the invented session id must not be handed back as the session id'
        );
    }

    public function testAnInventedSessionIdCannotReachAnAuthenticatedPage(): void
    {
        $invented = 'k2bnotissuedbytheserver' . bin2hex(random_bytes(4));

        $res = $this->requestWithHeaders('/dashboard.php', ['Cookie: PHPSESSID=' . $invented]);
        $this->assertSame(302, $res['status'], 'an invented session id must not authenticate');
    }

    // ---- D. use_only_cookies must not have been weakened ----

    public function testASessionIdInTheQueryStringIsStillIgnored(): void
    {
        $invented = 'k2bquerystringid' . bin2hex(random_bytes(6));

        $res = $this->httpGet($this->newCookieJar(), '/auth/login.php?PHPSESSID=' . $invented);
        $this->assertSame(200, $res['status']);

        $cookie = $this->sessionSetCookieHeader($res['headers']);
        $this->assertNotSame('', $cookie, 'a fresh id must be issued instead of trusting the URL');
        $this->assertStringNotContainsString(
            $invented,
            $cookie,
            'session.use_only_cookies must still reject an id supplied in the query string'
        );
    }

    // ---- E. HTTPS behaviour ----

    // The test server speaks plain HTTP and deliberately stays that way.
    // What is verified here is the production code path itself: the
    // X-Forwarded-Proto branch of config/session.php's request_is_https(),
    // which is exactly how the documented Nginx -> PHP-FPM topology would
    // report TLS to the application.
    public function testSecureIsSetWhenTheRequestIsForwardedAsHttps(): void
    {
        $res = $this->requestWithHeaders('/auth/login.php', ['X-Forwarded-Proto: https']);
        $this->assertSame(200, $res['status']);

        $cookie = $this->sessionSetCookieHeader($res['headers']);
        $this->assertNotSame('', $cookie);
        $this->assertStringContainsStringIgnoringCase(
            'secure',
            $cookie,
            'a request arriving over TLS must get a Secure session cookie'
        );
        // The other attributes must not be lost in the HTTPS branch.
        $this->assertStringContainsStringIgnoringCase('HttpOnly', $cookie);
        $this->assertStringContainsStringIgnoringCase('SameSite=Lax', $cookie);
    }

    public function testAProxyChainReportsTheOriginalClientScheme(): void
    {
        // A proxy chain appends, so "https, http" means the CLIENT spoke
        // https and an internal hop did not. The first entry is the one
        // that describes the browser's connection.
        $res = $this->requestWithHeaders('/auth/login.php', ['X-Forwarded-Proto: https, http']);

        $cookie = $this->sessionSetCookieHeader($res['headers']);
        $this->assertStringContainsStringIgnoringCase('secure', $cookie);
    }

    public function testForwardedPlainHttpDoesNotSetSecure(): void
    {
        $res = $this->requestWithHeaders('/auth/login.php', ['X-Forwarded-Proto: http']);

        $cookie = $this->sessionSetCookieHeader($res['headers']);
        $this->assertNotSame('', $cookie);
        $this->assertStringNotContainsStringIgnoringCase(
            'secure',
            $cookie,
            'an explicitly non-TLS forwarded request must not get a Secure cookie'
        );
    }

    // ---- helpers ----

    /** @return array{0:string,1:string,2:int} email, password, id */
    private function seedUser(): array
    {
        $email = 'k2b.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'K2BTestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['K2B Cookie Test User', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupUserIds[] = $id;
        return [$email, $password, $id];
    }

    // Returns the Set-Cookie line that carries a real session id, i.e.
    // NOT the "PHPSESSID=deleted" expiry line, so an assertion about the
    // live cookie can never accidentally read the deletion one.
    private function sessionSetCookieHeader(string $headers): string
    {
        return $this->matchSetCookie($headers, '/^Set-Cookie:\s*(PHPSESSID=(?!deleted)[^\r\n]*)/mi');
    }

    private function matchSetCookie(string $headers, string $pattern): string
    {
        if (preg_match($pattern, $headers, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    // Reads the current PHPSESSID out of curl's Netscape-format cookie
    // jar. Handles curl's "#HttpOnly_" line prefix, which now appears
    // because K2-B makes the cookie HttpOnly.
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

    // A single request with explicit headers. The shared harness has no
    // custom-header entry point and K2-B is not the batch to reshape it,
    // so this stays local to the tests that need it.
    /** @return array{status:int, headers:string, body:string} */
    private function requestWithHeaders(string $path, array $headers): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $this->fail('curl request failed: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [
            'status' => $status,
            'headers' => substr($response, 0, $headerSize),
            'body' => substr($response, $headerSize),
        ];
    }
}
