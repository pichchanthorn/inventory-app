<?php
declare(strict_types=1);

namespace Tests\Unit;

use PDOException;
use PHPUnit\Framework\TestCase;

// J5 - Backup Reliability & Auditability (Finding 1: error handling).
//
// Pure-function test for includes/backup.php::backupFailureMessage() - no
// database or session state involved. The property under test is the one
// that actually matters for this finding: whatever the real exception
// says, the message handed back for the still-streaming response must
// never repeat it verbatim.
final class BackupFailureMessageTest extends TestCase
{
    public function testNeverLeaksTheRawExceptionMessage(): void
    {
        // Modeled directly on a real MySQL/MariaDB permission-denied error
        // observed while building this fix - it names a user, a host, a
        // database, and a table, none of which may reach the browser.
        $e = new PDOException(
            "SQLSTATE[42000]: Syntax error or access violation: 1142 " .
            "SELECT command denied to user 'shopadmin'@'10.0.0.5' for table `inventory_db`.`customer_debts`"
        );

        $message = backupFailureMessage($e);

        $this->assertStringNotContainsString('shopadmin', $message);
        $this->assertStringNotContainsString('10.0.0.5', $message);
        $this->assertStringNotContainsString('inventory_db', $message);
        $this->assertStringNotContainsString('customer_debts', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
    }

    public function testReturnsAFixedGenericAdminFacingMessage(): void
    {
        $message = backupFailureMessage(new PDOException('anything at all'));

        $this->assertStringContainsString('did not complete successfully', $message);
        $this->assertStringContainsString('administrator', $message);
    }

    public function testMessageIsSafeToAppendToAnInProgressSqlDumpStream(): void
    {
        // The failure can land mid-stream, right after a real INSERT
        // statement - the message must start on its own line (a leading
        // newline) and read as an SQL comment, so it can never be
        // misread as more data by anything that later inspects the file.
        $message = backupFailureMessage(new PDOException('boom'));

        $this->assertStringStartsWith("\n", $message);
        $this->assertStringContainsString('-- ERROR:', $message);
    }
}
