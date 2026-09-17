<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Phase K2-E (residual security hardening).
//
// docker/php/Dockerfile ships NO php.ini at all, so the container runs
// on PHP's compiled defaults - measured directly on this build via
// `php -n`: display_errors=1, display_startup_errors=1, log_errors=0,
// expose_php=On. That is the opposite of what the shipped Docker
// deployment needs: PHP warnings/notices/uncaught-exception text would
// render straight into the HTTP response, and nothing would reach a log
// for an operator to see.
//
// This test loads docker/php/php.ini exactly the way PHP's conf.d
// mechanism would (a single -c ini file, no other configuration mixed
// in) and asserts the FIVE settings K2-E actually specified - not a
// live Docker container, which this environment cannot start, but the
// same effective-ini-value check used to verify this file manually
// before it was wired into the Dockerfile.
final class DockerPhpProductionIniTest extends TestCase
{
    public function testTheProductionIniDisablesErrorDisplayAndVersionDisclosure(): void
    {
        $values = $this->effectiveIniValues();

        $this->assertFalse((bool) $values['display_errors'], 'display_errors must be Off in the shipped Docker image');
        $this->assertFalse((bool) $values['display_startup_errors'], 'display_startup_errors must be Off');
        $this->assertFalse((bool) $values['expose_php'], 'expose_php must be Off - this is what removes the X-Powered-By: PHP/x.y.z header');
    }

    public function testTheProductionIniStillLogsErrorsToContainerStderr(): void
    {
        $values = $this->effectiveIniValues();

        $this->assertTrue((bool) $values['log_errors'], 'log_errors must stay On - Off would silence diagnostics entirely, not just hide them from the browser');
        $this->assertSame(
            '/proc/self/fd/2',
            $values['error_log'],
            'error_log must target the container\'s own stderr, which is what `docker logs` reads from PID 1'
        );
    }

    public function testTheIniFileTouchesOnlyTheFiveApprovedDirectives(): void
    {
        $iniPath = dirname(__DIR__, 2) . '/docker/php/php.ini';
        $lines = file($iniPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $directives = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === ';') {
                continue;
            }
            if (preg_match('/^([a-zA-Z0-9_.]+)\s*=/', $trimmed, $m)) {
                $directives[] = $m[1];
            }
        }
        sort($directives);
        $this->assertSame(
            ['display_errors', 'display_startup_errors', 'error_log', 'expose_php', 'log_errors'],
            $directives,
            'K2-E scope is exactly these five settings - no unrelated PHP setting may be added here'
        );
    }

    /** @return array<string,string> */
    private function effectiveIniValues(): array
    {
        $iniPath = dirname(__DIR__, 2) . '/docker/php/php.ini';
        $this->assertFileExists($iniPath);

        $code = 'foreach (['
            . "'display_errors','display_startup_errors','log_errors','error_log','expose_php'"
            . '] as $k) { echo $k, "=", ini_get($k), "\n"; }';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // -n: ignore every OTHER php.ini on this machine, so only the
        // file under test can be responsible for the result.
        $proc = proc_open([PHP_BINARY, '-n', '-c', $iniPath, '-r', $code], $descriptors, $pipes);
        $this->assertIsResource($proc);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $values = [];
        foreach (explode("\n", trim($stdout)) as $line) {
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $values[$k] = $v;
        }
        return $values;
    }
}
