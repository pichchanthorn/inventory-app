<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase K2-C (login brute-force protection + timing-oracle hardening).
//
// Drives the real auth/login.php over real HTTP. Two separate defects
// are pinned here, and they fail differently against the pre-K2-C code:
//
//   * unlimited attempts - measured beforehand at 33 consecutive
//     failures with no delay, no lockout and no record, after which the
//     correct password was still accepted immediately.
//   * a username-enumeration timing oracle - an address with no account
//     answered in ~0.0011s against ~0.2324s for a real one, because
//     password_verify() was never reached when the lookup returned
//     nothing. A ~210x tell, at one request per address.
//
// Every assertion about "refused" here is deliberately also an
// assertion about INDISTINGUISHABILITY: same status, same message, same
// body. A throttle that announced itself would just be a different
// enumeration oracle.
//
// Attempt counts are always scoped to an address this test seeded
// itself, never a raw table total - the suite's committed-data
// philosophy (see tests/TestCase.php) means other tests' rows may be
// present in the same table.
final class LoginThrottleTest extends HttpServerTestCase
{
    private const MAX_ATTEMPTS = 5;

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

    // ---- threshold behaviour ----

    public function testAFirstFailedLoginStillBehavesNormally(): void
    {
        [$email] = $this->seedUser();

        $res = $this->failOnce($email);
        $this->assertSame(200, $res['status'], 'a failed login re-renders the form');
        $this->assertStringContainsString('Invalid email or password', $res['body']);
        $this->assertSame(1, $this->attemptCount($email), 'the failure must be recorded');
    }

    public function testFourFailuresStillReachNormalAuthentication(): void
    {
        [$email, $password] = $this->seedUser();

        for ($i = 0; $i < 4; $i++) {
            $this->failOnce($email);
        }
        $this->assertSame(4, $this->attemptCount($email));

        // Still under the threshold, so the correct password must work.
        $res = $this->httpPost($this->newCookieJar(), '/auth/login.php', [
            'email' => $email, 'password' => $password,
        ]);
        $this->assertSame(302, $res['status'], 'four failures must not block a correct password');
    }

    public function testTheFifthFailureIsStillVerifiedAndRecorded(): void
    {
        [$email] = $this->seedUser();

        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $res = $this->failOnce($email);
            $this->assertSame(200, $res['status']);
        }

        // The 5th is the last one that goes through real verification;
        // it is recorded, which is what makes the 6th the first refusal.
        $this->assertSame(self::MAX_ATTEMPTS, $this->attemptCount($email));
    }

    public function testTheSixthAttemptWithinTheWindowIsRefusedWithoutPasswordWork(): void
    {
        [$email] = $this->seedUser();
        $this->failTimes($email, self::MAX_ATTEMPTS);

        $res = $this->failOnce($email);
        $this->assertSame(200, $res['status']);

        // A refused request never reaches password_verify(), so it
        // records nothing - the count is the observable proof that the
        // short-circuit happened before the work, not after it.
        $this->assertSame(
            self::MAX_ATTEMPTS,
            $this->attemptCount($email),
            'a refused attempt must not be verified, and so must not add a row'
        );
    }

    public function testTheCorrectPasswordIsAlsoRefusedWhileThrottled(): void
    {
        [$email, $password] = $this->seedUser();
        $this->failTimes($email, self::MAX_ATTEMPTS);

        $res = $this->httpPost($this->newCookieJar(), '/auth/login.php', [
            'email' => $email, 'password' => $password,
        ]);
        $this->assertSame(200, $res['status'], 'throttling must not be bypassable by knowing the password');
        $this->assertStringContainsString('Invalid email or password', $res['body']);
    }

    public function testARefusedResponseIsIndistinguishableFromAnOrdinaryFailure(): void
    {
        [$ordinaryEmail] = $this->seedUser();
        [$throttledEmail] = $this->seedUser();

        $ordinary = $this->failOnce($ordinaryEmail);

        $this->failTimes($throttledEmail, self::MAX_ATTEMPTS);
        $refused = $this->failOnce($throttledEmail);

        $this->assertSame($ordinary['status'], $refused['status'], 'status must not differ');
        $this->assertSame(
            strlen($ordinary['body']),
            strlen($refused['body']),
            'body length must not differ - a size difference is an oracle'
        );
        $this->assertSame($ordinary['body'], $refused['body'], 'the rendered page must be identical');
    }

    // ---- clearing and expiry ----

    public function testASuccessfulLoginClearsTheHistoryAndStillRotatesTheSession(): void
    {
        [$email, $password] = $this->seedUser();
        $this->failTimes($email, 3);
        $this->assertSame(3, $this->attemptCount($email));

        $jar = $this->newCookieJar();
        $this->httpGet($jar, '/auth/login.php');
        $beforeId = $this->sessionIdFromJar($jar);
        $this->assertNotSame('', $beforeId);

        $res = $this->httpPost($jar, '/auth/login.php', ['email' => $email, 'password' => $password]);
        $this->assertSame(302, $res['status'], 'login must succeed: ' . $res['body']);

        $this->assertSame(0, $this->attemptCount($email), 'success must wipe the failure history');

        // K2-A must still hold on the K2-C code path.
        $this->assertNotSame($beforeId, $this->sessionIdFromJar($jar), 'the session id must still rotate on login');
        $this->assertSame(200, $this->httpGet($jar, '/dashboard.php')['status']);
    }

    public function testThrottlingLiftsByItselfOnceTheWindowHasPassedAndIsNeverPermanent(): void
    {
        [$email, $password] = $this->seedUser();
        $this->failTimes($email, self::MAX_ATTEMPTS);

        // Confirm it really is throttled before proving it recovers.
        $this->assertSame(
            200,
            $this->httpPost($this->newCookieJar(), '/auth/login.php', ['email' => $email, 'password' => $password])['status'],
            'precondition: throttled'
        );

        // Age the recorded attempts past the 10-minute window. Nothing
        // is deleted and no admin acts - the same rows simply stop
        // counting, which is what "self-expiring, no permanent lockout"
        // means.
        $stmt = $this->pdo->prepare(
            'UPDATE login_attempts SET attempted_at = DATE_SUB(NOW(), INTERVAL 11 MINUTE) WHERE email = ?'
        );
        $stmt->execute([strtolower($email)]);
        $this->assertSame(self::MAX_ATTEMPTS, $this->rawRowCount($email), 'the rows are still there, just stale');

        $res = $this->httpPost($this->newCookieJar(), '/auth/login.php', ['email' => $email, 'password' => $password]);
        $this->assertSame(302, $res['status'], 'the account must recover on its own, with no unlock step');
    }

    // ---- scoping ----

    public function testThrottlingOneAccountDoesNotAffectAnother(): void
    {
        [$victimEmail] = $this->seedUser();
        [$otherEmail, $otherPassword] = $this->seedUser();

        $this->failTimes($victimEmail, self::MAX_ATTEMPTS);
        $this->assertSame(200, $this->failOnce($victimEmail)['status'], 'precondition: first account throttled');

        $res = $this->httpPost($this->newCookieJar(), '/auth/login.php', [
            'email' => $otherEmail, 'password' => $otherPassword,
        ]);
        $this->assertSame(302, $res['status'], 'a second account must be unaffected');
        $this->assertSame(0, $this->attemptCount($otherEmail));
    }

    public function testAnUnknownEmailIsCountedAndThrottledToo(): void
    {
        $ghost = 'k2c.ghost.' . bin2hex(random_bytes(4)) . '@test.local';
        $this->cleanupEmails[] = $ghost;

        $this->failTimes($ghost, self::MAX_ATTEMPTS);
        $this->assertSame(
            self::MAX_ATTEMPTS,
            $this->attemptCount($ghost),
            'attempts against an address with no account must be counted - those are most of a brute-force run'
        );

        $this->failOnce($ghost);
        $this->assertSame(self::MAX_ATTEMPTS, $this->attemptCount($ghost), 'and must then be refused');
    }

    // A case-sensitive throttle key would be no throttle at all: the
    // login lookup is case-insensitive (users.email is
    // utf8mb4_general_ci), so every capitalisation of one address
    // authenticates the same account and would otherwise get its own
    // fresh five-attempt budget.
    public function testCaseVariationsOfOneAddressShareASingleThrottle(): void
    {
        [$email, $password] = $this->seedUser();
        $this->failTimes($email, self::MAX_ATTEMPTS);

        $res = $this->httpPost($this->newCookieJar(), '/auth/login.php', [
            'email' => strtoupper($email), 'password' => $password,
        ]);
        $this->assertSame(200, $res['status'], 'changing the case must not hand out a new attempt budget');
        $this->assertSame(
            self::MAX_ATTEMPTS,
            $this->attemptCount($email),
            'the upper-case attempt must not have been verified or recorded separately'
        );
    }

    // ---- timing oracle ----

    public function testUnknownAndKnownAddressFailuresCostComparablePasswordWork(): void
    {
        [$knownEmail] = $this->seedUser();
        $ghost = 'k2c.timing.' . bin2hex(random_bytes(4)) . '@test.local';
        $this->cleanupEmails[] = $ghost;

        // Median of three, and the attempt history is cleared between
        // samples so neither address ever reaches the threshold - a
        // refused request skips the hash by design and would poison the
        // measurement.
        $unknown = $this->medianFailureSeconds($ghost);
        $known = $this->medianFailureSeconds($knownEmail);

        $this->assertGreaterThan(0.0, $unknown);
        $this->assertGreaterThan(0.0, $known);

        // A deliberately loose bound: both paths now run one real
        // bcrypt verify, so the ratio sits near 1, while the defect
        // this guards against was ~210x. Anything under 5x is noise on
        // a busy machine; anything over it means a branch stopped doing
        // the work.
        $ratio = max($unknown, $known) / min($unknown, $known);
        $this->assertLessThan(
            5.0,
            $ratio,
            sprintf(
                'unknown-address and known-address failures must cost comparable work ' .
                '(unknown %.4fs vs known %.4fs, ratio %.1fx)',
                $unknown,
                $known,
                $ratio
            )
        );
    }

    // ---- K2-B compatibility ----

    public function testAThrottledResponseStillCarriesTheHardenedSessionCookie(): void
    {
        [$email] = $this->seedUser();
        $this->failTimes($email, self::MAX_ATTEMPTS);

        $res = $this->failOnce($email);

        // The refusal short-circuits before the users table, so this
        // also proves the early return did not skip session setup.
        $this->assertMatchesRegularExpression('/^Set-Cookie:\s*PHPSESSID=/mi', $res['headers']);
        $this->assertMatchesRegularExpression('/^Set-Cookie:[^\n]*HttpOnly/mi', $res['headers']);
        $this->assertMatchesRegularExpression('/^Set-Cookie:[^\n]*SameSite=Lax/mi', $res['headers']);
    }

    // ---- helpers ----

    /** @return array{0:string,1:string,2:int} email, password, id */
    private function seedUser(): array
    {
        $email = 'k2c.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'K2CTestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['K2C Throttle Test User', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupUserIds[] = $id;
        $this->cleanupEmails[] = $email;
        return [$email, $password, $id];
    }

    /** @return array{status:int, headers:string, body:string} */
    private function failOnce(string $email): array
    {
        return $this->httpPost($this->newCookieJar(), '/auth/login.php', [
            'email' => $email,
            'password' => 'not-the-password-' . bin2hex(random_bytes(3)),
        ]);
    }

    private function failTimes(string $email, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failOnce($email);
        }
    }

    // Attempts that currently COUNT - i.e. inside the rolling window,
    // the same condition the application itself applies.
    private function attemptCount(string $email): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)'
        );
        $stmt->execute([strtolower($email)]);
        return (int) $stmt->fetchColumn();
    }

    // Every row for this address regardless of age - used to show that
    // expiry is the window moving on, not rows being deleted.
    private function rawRowCount(string $email): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        return (int) $stmt->fetchColumn();
    }

    private function clearAttempts(string $email): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE email = ?');
        $stmt->execute([strtolower($email)]);
    }

    private function medianFailureSeconds(string $email): float
    {
        $samples = [];
        for ($i = 0; $i < 3; $i++) {
            $this->clearAttempts($email);
            $start = microtime(true);
            $this->failOnce($email);
            $samples[] = microtime(true) - $start;
        }
        sort($samples);
        return $samples[1];
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
