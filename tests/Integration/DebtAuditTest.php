<?php
declare(strict_types=1);

namespace Tests\Integration;

use DebtOverpaymentException;
use IdempotencyConflictException;
use Tests\TestCase;

// V2-B1: Debt & Payment Audit Trail. Exercises the logAudit() calls added
// to recordCreditSale()/recordDebtPayment() in includes/debt.php - both
// money-moving actions must now be auditable, without changing any of
// their existing concurrency/idempotency/business behavior (already fully
// covered by tests/Integration/DebtTest.php and tests/Integration/
// IdempotencyTest.php, not re-tested here).
final class DebtAuditTest extends TestCase
{
    // ---- Debt creation (recordCreditSale) ----

    public function testSuccessfulCreditSaleWritesExactlyOneAuditRow(): void
    {
        $product = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];
        $countBefore = $this->auditCount('customer_debt');

        $result = recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 4, 'price' => 3.00]], date('Y-m-d'), $userId, null, 'Audit Farmer', '012000010', '');

        $this->assertSame($countBefore + 1, $this->auditCount('customer_debt'), 'exactly one new audit_log row must be created for a successful credit sale');
    }

    public function testCreditSaleAuditRowHasCorrectActorEntityActionAndSnapshot(): void
    {
        $product = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];

        $result = recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 4, 'price' => 3.00]], date('Y-m-d'), $userId, null, 'Audit Farmer', '012000011', '2027-01-01');

        $debtId = $this->debtIdForReference($result['debt_reference']);
        $row = $this->latestAuditRow('customer_debt', $debtId);

        $this->assertSame($userId, (int) $row['user_id']);
        $this->assertSame('create', $row['action']);
        $this->assertSame('customer_debt', $row['entity_type']);
        $this->assertSame($debtId, (int) $row['entity_id']);
        $this->assertNull($row['before_snapshot'], 'a creation event must carry no before-snapshot');

        $after = json_decode($row['after_snapshot'], true);
        $this->assertSame($result['debt_reference'], $after['name']);
        $this->assertSame($result['debt_reference'], $after['reference']);
        $this->assertSame($result['customer_id'], $after['customer_id']);
        $this->assertEqualsWithDelta(12.00, $after['total_amount'], 0.001);
        $this->assertSame('2027-01-01', $after['due_date']);
    }

    public function testARolledBackCreditSaleWritesNoAuditRow(): void
    {
        $product = testSeedProduct($this->pdo, 20, ['track_batches' => 1]);
        $userId = testSeedUserRole($this->pdo)['id'];
        $countBefore = $this->auditCount('customer_debt');

        try {
            // A track_batches=1 product without consumeBatches=true is
            // rejected before the customer_debts INSERT is ever reached
            // (see DebtTest's own coverage of this safety gate) - the
            // whole transaction, including any audit write, must roll back.
            recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 4, 'price' => 3.00]], date('Y-m-d'), $userId, null, 'Rollback Audit Farmer', '012000012', '');
            $this->fail('Expected an exception from the untracked-batch safety gate.');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertSame($countBefore, $this->auditCount('customer_debt'), 'a rolled-back credit sale must not leave an audit row');
    }

    public function testAnIdempotentDuplicateCreditSaleWritesNoSecondAuditRow(): void
    {
        $product = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];
        $token = testRandomToken();

        recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 4, 'price' => 3.00]], date('Y-m-d'), $userId, null, 'Idempotent Audit Farmer', '012000013', '', $token);
        $countAfterFirst = $this->auditCount('customer_debt');

        try {
            recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 4, 'price' => 3.00]], date('Y-m-d'), $userId, null, 'Idempotent Audit Farmer', '012000013', '', $token);
            $this->fail('Expected IdempotencyConflictException was not thrown.');
        } catch (IdempotencyConflictException $e) {
            // expected
        }

        $this->assertSame($countAfterFirst, $this->auditCount('customer_debt'), 'a duplicate/idempotent resubmission must not create a second audit row');
    }

    // ---- Debt payment (recordDebtPayment) ----

    public function testSuccessfulDebtPaymentWritesExactlyOneAuditRow(): void
    {
        $debt = $this->seedDebt(100.00);
        $countBefore = $this->auditCount('customer_debt', $debt['id']);

        recordDebtPayment($this->pdo, $debt['id'], 30.00, date('Y-m-d'), 'first installment', $debt['userId']);

        $this->assertSame($countBefore + 1, $this->auditCount('customer_debt', $debt['id']), 'exactly one new audit_log row must be created for a successful debt payment');
    }

    public function testDebtPaymentAuditRowHasCorrectActorEntityActionAndSnapshot(): void
    {
        $debt = $this->seedDebt(100.00);

        recordDebtPayment($this->pdo, $debt['id'], 30.00, date('Y-m-d'), 'first installment', $debt['userId']);

        $row = $this->latestAuditRow('customer_debt', $debt['id']);

        $this->assertSame($debt['userId'], (int) $row['user_id']);
        $this->assertSame('update', $row['action']);
        $this->assertSame('customer_debt', $row['entity_type']);
        $this->assertSame($debt['id'], (int) $row['entity_id']);

        $before = json_decode($row['before_snapshot'], true);
        $this->assertEqualsWithDelta(0.00, $before['paid_amount'], 0.001);
        $this->assertEqualsWithDelta(100.00, $before['balance'], 0.001);
        $this->assertSame('open', $before['status']);

        $after = json_decode($row['after_snapshot'], true);
        $this->assertEqualsWithDelta(30.00, $after['paid_amount'], 0.001);
        $this->assertEqualsWithDelta(70.00, $after['balance'], 0.001);
        $this->assertSame('partially_paid', $after['status']);
        $this->assertEqualsWithDelta(30.00, $after['payment_this_event']['amount'], 0.001);
        $this->assertSame('first installment', $after['payment_this_event']['note']);
    }

    public function testDebtPaymentThatFullyClearsTheBalanceIsAuditedAsPaid(): void
    {
        $debt = $this->seedDebt(50.00);

        recordDebtPayment($this->pdo, $debt['id'], 50.00, date('Y-m-d'), 'paid in full', $debt['userId']);

        $row = $this->latestAuditRow('customer_debt', $debt['id']);
        $after = json_decode($row['after_snapshot'], true);
        $this->assertSame('paid', $after['status']);
        $this->assertEqualsWithDelta(0.00, $after['balance'], 0.001);
    }

    public function testARejectedOverpaymentWritesNoAuditRow(): void
    {
        $debt = $this->seedDebt(50.00);
        $countBefore = $this->auditCount('customer_debt', $debt['id']);

        try {
            recordDebtPayment($this->pdo, $debt['id'], 50.01, date('Y-m-d'), 'overpay attempt', $debt['userId']);
            $this->fail('Expected DebtOverpaymentException was not thrown.');
        } catch (DebtOverpaymentException $e) {
            // expected
        }

        $this->assertSame($countBefore, $this->auditCount('customer_debt', $debt['id']), 'a rejected overpayment must not create an audit row');
    }

    public function testAnIdempotentDuplicatePaymentWritesNoSecondAuditRow(): void
    {
        $debt = $this->seedDebt(100.00);
        $token = testRandomToken();

        recordDebtPayment($this->pdo, $debt['id'], 30.00, date('Y-m-d'), 'first installment', $debt['userId'], $token);
        $countAfterFirst = $this->auditCount('customer_debt', $debt['id']);

        try {
            recordDebtPayment($this->pdo, $debt['id'], 30.00, date('Y-m-d'), 'first installment', $debt['userId'], $token);
            $this->fail('Expected IdempotencyConflictException was not thrown.');
        } catch (IdempotencyConflictException $e) {
            // expected
        }

        $this->assertSame($countAfterFirst, $this->auditCount('customer_debt', $debt['id']), 'a duplicate/idempotent resubmission must not create a second audit row');
    }

    private function auditCount(string $entityType, ?int $entityId = null): int
    {
        if ($entityId !== null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE entity_type = ? AND entity_id = ?');
            $stmt->execute([$entityType, $entityId]);
            return (int) $stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE entity_type = ?');
        $stmt->execute([$entityType]);
        return (int) $stmt->fetchColumn();
    }

    private function latestAuditRow(string $entityType, int $entityId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit_log WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$entityType, $entityId]);
        $row = $stmt->fetch();
        $this->assertIsArray($row, 'expected an audit_log row for ' . $entityType . '#' . $entityId);
        return $row;
    }

    private function debtIdForReference(string $reference): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM customer_debts WHERE reference = ?');
        $stmt->execute([$reference]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{id:int, userId:int} */
    private function seedDebt(float $total): array
    {
        $product = testSeedProduct($this->pdo, 100, ['sale_price' => $total]);
        $userId = testSeedUserRole($this->pdo)['id'];
        $result = recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 1, 'price' => $total]], date('Y-m-d'), $userId, null, 'Debt Audit Test Customer', '', '');

        return ['id' => $this->debtIdForReference($result['debt_reference']), 'userId' => $userId];
    }
}
