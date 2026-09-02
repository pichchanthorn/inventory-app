<?php
// ================================================
// Pure-PHP full database backup (structure + data, all tables) - no
// exec()/shell_exec()/mysqldump dependency. Shared hosting (this app's
// likely deployment target) very commonly disables shell functions
// outright, and even where allowed, the mysqldump binary may not be on
// PATH or reachable by the PHP process. Building the dump directly over
// PDO works identically regardless of hosting restrictions and has zero
// shell-injection surface, since nothing here is ever passed to a shell.
//
// Streams DROP/CREATE/INSERT statements straight to output as they're
// built (via a dedicated unbuffered connection + periodic flush()),
// rather than assembling one giant string in memory first - cheap
// defensive practice for if this app's data ever grows, though at its
// actual scale (a single small shop) a multi-GB dump isn't a realistic
// concern today.
// ================================================

// $pdo: the app's normal (buffered) connection, used for the small
// introspection queries (SHOW TABLES / SHOW CREATE TABLE). $dsn/$user/
// $pass: same credentials as config/db.php, used to open a second,
// dedicated unbuffered connection for the potentially-large SELECT *
// reads - kept separate from $pdo so this never interferes with (or is
// interfered by) anything else on the request.
function streamDatabaseBackup(PDO $pdo, string $dsn, string $user, string $pass): void {
    $dump = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
    ]);

    echo "-- PCTN Inventory System - database backup\n";
    echo "-- Generated " . date('Y-m-d H:i:s') . "\n";
    echo "-- Restore via phpMyAdmin's Import, or: mysql -u USER -p DBNAME < this_file.sql\n\n";
    echo "SET NAMES utf8mb4;\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";
    flush();

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';

        echo "-- --------------------------------------------------\n";
        echo "-- Table: $table\n";
        echo "-- --------------------------------------------------\n";
        echo "DROP TABLE IF EXISTS $quoted;\n";
        $createRow = $pdo->query("SHOW CREATE TABLE $quoted")->fetch();
        echo $createRow['Create Table'] . ";\n\n";
        flush();

        // Generated columns (STORED or VIRTUAL - e.g. customer_debts.balance/
        // status) must never appear in an INSERT: MySQL/MariaDB computes them
        // itself from the row's other values and rejects an explicit value
        // outright, which used to abort the restore partway through and
        // silently drop that table's data. Detected generically via
        // information_schema rather than naming any table/column here, so
        // this holds for any current or future generated column.
        $colStmt = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%'
             ORDER BY ORDINAL_POSITION"
        );
        $colStmt->execute([$table]);
        $insertableColumns = $colStmt->fetchAll(PDO::FETCH_COLUMN);

        // A table where every column is generated has nothing to insert -
        // not the case anywhere in this app today, but skip cleanly rather
        // than emit an empty INSERT INTO tbl () VALUES () that wouldn't
        // even parse.
        if (!$insertableColumns) {
            echo "\n";
            continue;
        }

        $columnList = implode(',', array_map(fn($c) => '`' . $c . '`', $insertableColumns));

        $stmt = $dump->query("SELECT $columnList FROM $quoted");
        $batch = [];
        $batchSize = 200;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $values = array_map(fn($v) => $v === null ? 'NULL' : $dump->quote($v), array_values($row));
            $batch[] = '(' . implode(',', $values) . ')';

            if (count($batch) >= $batchSize) {
                echo "INSERT INTO $quoted ($columnList) VALUES\n" . implode(",\n", $batch) . ";\n";
                flush();
                $batch = [];
            }
        }
        // $dump only allows one unbuffered result set open at a time, so
        // this must be closed before the next table's SELECT reuses it.
        $stmt->closeCursor();

        if ($batch) {
            echo "INSERT INTO $quoted ($columnList) VALUES\n" . implode(",\n", $batch) . ";\n";
            flush();
        }
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    flush();
}

// Called by settings/index.php when streamDatabaseBackup() throws partway
// through - the download headers are already sent by that point (a file
// download response can't be downgraded to an HTML error page once
// started), so this is the one remaining safe way to tell the Admin
// something went wrong. Logs the real exception server-side, where an
// operator can actually see it, and returns a short, fixed, generic
// message to append to the still-open output stream - the raw exception
// message is never used here, since it can contain table/column/schema
// or connection details (confirmed directly against a real MySQL
// permission-denied error) that must never reach the browser.
function backupFailureMessage(Throwable $e): string {
    error_log('Database backup failed: ' . $e->getMessage());
    return "\n-- ERROR: the backup did not complete successfully. Contact an administrator.\n";
}
