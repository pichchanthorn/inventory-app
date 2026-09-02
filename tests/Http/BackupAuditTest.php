<?php
declare(strict_types=1);

namespace Tests\Http;

// J5 - Backup Reliability & Auditability (Finding 2: audit logging).
//
// Drives the real settings/index.php backup action over real HTTP (see
// HttpServerTestCase for why) - this exercises the actual page-controller
// branch that now calls logAudit(), not just includes/backup.php's own
// dumping logic (already covered by tests/Backup/BackupRestoreTest.php).
final class BackupAuditTest extends HttpServerTestCase
{
    private array $cleanupUserIds = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupUserIds as $id) {
            $this->pdo->exec("DELETE FROM audit_log WHERE user_id = $id AND entity_type = 'backup'");
            $this->pdo->exec("DELETE FROM users WHERE id = $id");
        }
        parent::tearDown();
    }

    public function testSuccessfulBackupWritesExactlyOneSafeAuditLogRow(): void
    {
        $admin = $this->seedUser(1);
        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);

        $form = $this->httpGet($jar, '/settings/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $countBefore = $this->countBackupAuditRows($admin['id']);

        $res = $this->httpPost($jar, '/settings/index.php', [
            'csrf_token' => $token,
            'action' => 'backup',
        ]);

        $this->assertSame(200, $res['status'], 'a successful backup must still return the file response: ' . $res['body']);
        $this->assertStringContainsString('Content-Disposition: attachment', $res['headers'], 'the response must still be a real file download, unaffected by the new try/catch');
        $this->assertStringContainsString('PCTN Inventory System - database backup', $res['body'], 'the backup content itself must be unchanged');

        $this->assertSame($countBefore + 1, $this->countBackupAuditRows($admin['id']), 'exactly one new audit_log row must be created for this backup');

        $stmt = $this->pdo->prepare("SELECT action, entity_type, entity_id, before_snapshot, after_snapshot FROM audit_log WHERE user_id = ? AND entity_type = 'backup' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$admin['id']]);
        $row = $stmt->fetch();

        $this->assertSame('create', $row['action']);
        $this->assertSame('backup', $row['entity_type']);
        $this->assertSame(0, (int) $row['entity_id']);
        $this->assertNull($row['before_snapshot']);

        $snapshot = json_decode($row['after_snapshot'], true);
        $this->assertArrayHasKey('filename', $snapshot);
        $this->assertMatchesRegularExpression('/^pctn-backup-\d{4}-\d{2}-\d{2}_\d{6}\.sql$/', $snapshot['filename']);

        // Never anything beyond the filename - no SQL content, no
        // credentials, no arbitrary extra keys.
        $this->assertSame(['filename'], array_keys($snapshot));
    }

    public function testViewerCannotTriggerBackup(): void
    {
        // RBAC must remain exactly as strict as before this fix - the
        // isAdmin() gate at the top of settings/index.php is unchanged,
        // this just confirms the new code didn't loosen it.
        $viewer = $this->seedUser(3);
        $jar = $this->newCookieJar();
        $this->login($jar, $viewer['email'], $viewer['password']);

        $res = $this->httpGet($jar, '/settings/index.php');
        $this->assertSame(302, $res['status'], 'a Viewer must still be redirected away from Settings entirely');
        $this->assertStringContainsString('dashboard.php', $res['headers']);
    }

    public function testMissingCsrfTokenIsStillRejectedAndCreatesNoAuditRow(): void
    {
        $admin = $this->seedUser(1);
        $jar = $this->newCookieJar();
        $this->login($jar, $admin['email'], $admin['password']);
        $this->httpGet($jar, '/settings/index.php'); // establishes the session's csrf_token
        $countBefore = $this->countBackupAuditRows($admin['id']);

        $res = $this->httpPost($jar, '/settings/index.php', ['action' => 'backup']);

        $this->assertSame(403, $res['status'], 'CSRF enforcement must remain exactly as strict as before this fix');
        $this->assertSame($countBefore, $this->countBackupAuditRows($admin['id']), 'a rejected request must never create an audit row');
    }

    private function countBackupAuditRows(int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE user_id = ? AND entity_type = 'backup'");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{id:int,email:string,password:string} */
    private function seedUser(int $roleId): array
    {
        $email = 'j5backup.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,?)');
        $stmt->execute(['J5 Backup Test User', $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupUserIds[] = $id;
        return ['id' => $id, 'email' => $email, 'password' => $password];
    }
}
