<?php
declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;

// Base class for Integration tests.
//
// IMPORTANT: this does NOT wrap each test in an outer transaction. Every
// mutating function under test here (recordStockIn/recordStockOut/
// adjustStock/recordCreditSale/recordDebtPayment) begins and commits/
// rolls back ITS OWN transaction internally, end to end - that is the
// real, unmodified production design (see includes/stock.php's own
// header comment). PDO does not support nested transactions, so an
// outer beginTransaction() here would make every one of those calls
// throw "There is already an active transaction" the moment it tried to
// start its own - and worse, a subsequent rollback from inside the
// production function's own catch block would silently roll back this
// test's OWN seed data along with it. Wrapping in a transaction is
// simply not compatible with code that manages its own transactions.
//
// Isolation instead comes from each test seeding its own rows with
// randomized unique identifiers (see tests/fixtures/seed_helpers.php)
// and asserting on before/after deltas or on rows scoped to IDs/
// references it just created itself - never on a table's raw total
// count - so accumulated data from other tests in the same run can never
// affect the result. Concurrency and Http tests follow the same
// committed-data philosophy for the same underlying reason.
abstract class TestCase extends BaseTestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $GLOBALS['__TEST_PDO'];
    }
}
