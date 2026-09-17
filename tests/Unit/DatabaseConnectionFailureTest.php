<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Phase K2-E (residual security hardening).
//
// Reproduces the exact scenario found during inspection: config/db.php's
// PDOException handler used to die() with the real exception message,
// which for a credentials failure looks like
// "SQLSTATE[HY000] [1045] Access denied for user 'shopadmin'@'localhost'
// (using password: YES)" - naming the DB username and host to anyone who
// happens to hit the app while the database is unreachable.
//
// This runs config/db.php in a real, separate PHP process (not required
// in-process) against the ALREADY-RUNNING test database, with only
// DB_PASSWORD deliberately wrong - a real PDOException, not a fabricated
// one, exercising the actual require'd file rather than a refactored-out
// helper function.
final class DatabaseConnectionFailureTest extends TestCase
{
    public function testAConnectionFailureShowsOnlyAGenericMessageToTheOutput(): void
    {
        [$stdout, $stderr, $exitCode] = $this->runDbPhpWithWrongPassword();

        $this->assertSame(0, $exitCode, 'die() with a string argument must not itself signal a process failure');

        // The one thing an unauthenticated visitor may see.
        $this->assertStringContainsString(
            'temporarily unavailable',
            $stdout,
            'the visitor-facing output must still say SOMETHING (fail-closed, not fail-silent)'
        );

        // Everything a raw PDOException message would have contained.
        foreach (['SQLSTATE', 'Access denied', getenv('DB_USERNAME') ?: 'test_user', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_DATABASE') ?: 'inventory_test'] as $sensitive) {
            $this->assertStringNotContainsString(
                $sensitive,
                $stdout,
                "the output must not contain '$sensitive'"
            );
        }
    }

    public function testExecutionStopsAndDoesNotContinuePastTheFailure(): void
    {
        [$stdout] = $this->runDbPhpWithWrongPassword();

        $this->assertStringNotContainsString(
            'UNREACHABLE_MARKER',
            $stdout,
            'die() must actually stop the request - the app must not continue with no $pdo'
        );
    }

    public function testTheRealExceptionDetailIsStillAvailableThroughErrorLogging(): void
    {
        [, $stderr] = $this->runDbPhpWithWrongPassword();

        // PHP CLI's default error_log target is stderr, which is what
        // this project's own docker/php/php.ini points at
        // (/proc/self/fd/2, the container's stderr) - so this is the
        // same channel a real deployment's diagnostics travel through,
        // without this test depending on any file path or log
        // destination.
        $this->assertStringContainsString(
            'Database connection failed',
            $stderr,
            'the real failure must still be diagnosable through the server-side log, just not in the response'
        );
    }

    /** @return array{0:string,1:string,2:int} stdout, stderr, exit code */
    private function runDbPhpWithWrongPassword(): array
    {
        $script = dirname(__DIR__, 2) . '/config/db.php';
        $code = 'require ' . var_export($script, true) . '; echo "UNREACHABLE_MARKER";';

        $env = [
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_DATABASE' => getenv('DB_DATABASE') ?: 'inventory_test',
            'DB_USERNAME' => getenv('DB_USERNAME') ?: 'test_user',
            // Deliberately wrong - this is what produces a REAL
            // PDOException from the already-running test database,
            // rather than simulating one.
            'DB_PASSWORD' => 'definitely-wrong-' . bin2hex(random_bytes(4)),
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, '-r', $code], $descriptors, $pipes, null, $env);
        $this->assertIsResource($proc, 'failed to launch the subprocess');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        return [$stdout, $stderr, $exitCode];
    }
}
