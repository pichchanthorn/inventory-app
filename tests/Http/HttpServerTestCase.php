<?php
declare(strict_types=1);

namespace Tests\Http;

use PDO;
use PHPUnit\Framework\TestCase;

// Shared base for the HTTP-level tests (P0 #15 RBAC, #16 CSRF).
//
// includes/csrf.php's csrf_verify() calls exit() directly on failure, and
// includes/auth_check.php performs file-scope header()+exit redirects
// when there is no valid session - both make the real page-controllers
// impractical to include in-process inside a PHPUnit test. Rather than
// changing either file for testability, this spins up PHP's own built-in
// dev server against the real, unmodified application files and drives
// it with real HTTP requests via curl - the same approach a browser
// uses, so session/redirect/exit all behave exactly as in production.
//
// Does NOT extend Tests\TestCase: requests here are handled by a
// separate PHP process (the built-in server), which cannot see an open
// transaction held by this process. Seed data is committed directly via
// $GLOBALS['__TEST_PDO'] and removed again in tearDown().
abstract class HttpServerTestCase extends TestCase
{
    private static $serverProcess;
    protected static string $baseUrl;
    protected PDO $pdo;
    private array $cookieJars = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $port = 8971;
        self::$baseUrl = "http://127.0.0.1:{$port}";
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__, 2)],
            $descriptors,
            $pipes
        );
        if (!is_resource(self::$serverProcess)) {
            self::fail('Could not start the PHP built-in server for HTTP tests.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Wait for the server to actually accept connections instead of a
        // fixed sleep - avoids both a flaky too-short wait and wasting
        // time on a machine where it starts instantly.
        $deadline = microtime(true) + 5.0;
        $up = false;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) {
                fclose($conn);
                $up = true;
                break;
            }
            usleep(50000);
        }
        if (!$up) {
            self::fail('PHP built-in server did not become ready in time.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $GLOBALS['__TEST_PDO'];
    }

    protected function tearDown(): void
    {
        foreach ($this->cookieJars as $jar) {
            @unlink($jar);
        }
        parent::tearDown();
    }

    protected function newCookieJar(): string
    {
        $jar = tempnam(sys_get_temp_dir(), 'j1cookie');
        $this->cookieJars[] = $jar;
        return $jar;
    }

    /** @return array{status:int, headers:string, body:string} */
    protected function httpGet(string $cookieJar, string $path): array
    {
        return $this->request('GET', $cookieJar, $path);
    }

    /** @return array{status:int, headers:string, body:string} */
    protected function httpPost(string $cookieJar, string $path, array $fields): array
    {
        return $this->request('POST', $cookieJar, $path, $fields);
    }

    private function request(string $method, string $cookieJar, string $path, array $fields = []): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_TIMEOUT => 10,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
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

    // Scrapes the csrf_token hidden-input value out of a rendered form
    // page - the same value the real browser would submit back.
    protected function extractCsrfToken(string $html): string
    {
        if (!preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m)) {
            $this->fail('Could not find a csrf_token field in the response HTML.');
        }
        return $m[1];
    }

    protected function login(string $cookieJar, string $email, string $password): void
    {
        $res = $this->httpPost($cookieJar, '/auth/login.php', ['email' => $email, 'password' => $password]);
        $this->assertSame(302, $res['status'], "login failed for {$email}, expected a redirect: " . $res['body']);
    }
}
