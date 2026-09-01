<?php
declare(strict_types=1);

namespace Tests;

use PDO;

// Minimal helper shared by the test bootstrap and the migration-integrity
// test: reads a plain .sql file, strips comment lines and the
// `CREATE DATABASE`/`USE` statements every schema/migration file in this
// repo starts with (they always target the literal `inventory_db` name,
// never the test database), and executes the remaining statements one at
// a time against whatever PDO connection is handed in. No other tool is
// used to apply schema.sql/migrations - this exists purely so the test
// suite can point the exact same, unmodified SQL files at an isolated
// test database.
class SchemaBuilder
{
    public function __construct(private PDO $pdo)
    {
    }

    // Drops every table currently in the connected database, so each
    // fresh build starts from a truly empty schema regardless of what a
    // previous run left behind.
    public function dropAllTables(): void
    {
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if (!$tables) {
            return;
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // Rebuilds the connected (test) database from the project's real
    // database/schema.sql - the same file a fresh production install
    // uses - so the suite always exercises the current, real schema.
    public function buildFreshFromSchema(): void
    {
        $this->dropAllTables();
        $this->runSqlFile(dirname(__DIR__) . '/database/schema.sql');
    }

    public function runSqlFile(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("Could not read SQL file: $path");
        }

        foreach ($this->splitStatements($sql) as $statement) {
            $this->pdo->exec($statement);
        }
    }

    // Strips full-line `--` comments, drops any `CREATE DATABASE ...` or
    // `USE ...` statement (this test suite selects its database purely
    // via the DB_DATABASE env var / DSN, never via an in-SQL USE), and
    // splits the remainder on statement-terminating semicolons. Every
    // schema/migration file in this repo is simple sequential DDL/DML
    // with no stored routines or semicolons embedded inside a single
    // statement, so a plain split is safe here.
    private function splitStatements(string $sql): array
    {
        $lines = explode("\n", $sql);
        $clean = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line)) {
                continue;
            }
            $clean[] = $line;
        }
        $sql = implode("\n", $clean);

        $statements = [];
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $statement)) {
                continue;
            }
            $statements[] = $statement;
        }
        return $statements;
    }
}
