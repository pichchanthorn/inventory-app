<?php
declare(strict_types=1);

namespace Tests\Integration;

use BatchConsumptionRequiredException;
use DebtOverpaymentException;
use Tests\TestCase;

// P0 #11-13 (Customer / Debt): Credit Sale, Debt Payment, Overpayment.
// Exercises includes/debt.php's real functions.
final class DebtTest extends TestCase
{
    // ---- K3-1: POS safety gate for track_batches=1 products ----
    //
    // recordCreditSale() itself is NOT modified by K3-1 - it still calls
    // insertStockOutLines($pdo, $txId, $lines) with no $consumeBatches
    // argument, which defaults to false. This exercises that exact,
    // unmodified call shape directly.

    public function testCreditSaleOfATrackedProductIsRejectedWithoutMutatingAnything(): void
    {
        $product = testSeedProduct($this->pdo, 20, ['track_batches' => 1]);
        $userId = testSeedUserRole($this->pdo)['id'];
        $stmt = $this->pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, qty_received, qty_on_hand) VALUES (?,?,?,?,?)');
        $stmt->execute([$product['id'], 'LOT-CREDIT', '2027-01-01', 20, 20]);
        $batchId = (int) $this->pdo->lastInsertId();
        $customerCountBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        $debtCountBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM customer_debts')->fetchColumn();
        $txCountBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM stock_transactions')->fetchColumn();
        $token = testRandomToken();

        try {
            // A brand-new customer, created inline in the SAME
            // transaction - this must roll back too, not just the sale.
            recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 4, 'price' => 3.00]], date('Y-m-d'), $userId, null, 'Rejected Sale Farmer', '099999999', '', $token);
            $this->fail('Expected BatchConsumptionRequiredException was not thrown.');
        } catch (BatchConsumptionRequiredException $e) {
            $this->assertSame($product['id'], $e->productId);
        }

        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$product['id']]);
        $this->assertSame(20, (int) $stmt->fetchColumn(), 'current_stock must be unchanged after a rejected sale');

        $stmt = $this->pdo->prepare('SELECT qty_on_hand FROM product_batches WHERE id = ?');
        $stmt->execute([$batchId]);
        $this->assertSame(20, (int) $stmt->fetchColumn(), 'batch qty_on_hand must be unchanged after a rejected sale');

        $this->assertSame($customerCountBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(), 'the inline new-customer insert must not survive');
        $this->assertSame($debtCountBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM customer_debts')->fetchColumn(), 'no debt row must survive');
        $this->assertSame($txCountBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM stock_transactions')->fetchColumn(), 'no stock_transactions row must survive');

        // The idempotency claim must have rolled back too.
        $untracked = testSeedProduct($this->pdo, 10);
        $result = recordCreditSale($this->pdo, [['product_id' => $untracked['id'], 'qty' => 2, 'price' => 1.00]], date('Y-m-d'), $userId, null, 'Retry Farmer', '011111111', '', $token);
        $this->assertStringStartsWith('SAL-', $result['reference']);
    }

    public function testCreditSaleOfAnUntrackedProductRemainsUnaffectedByTheSafetyGate(): void
    {
        $product = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];

        $result = recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 4, 'price' => 3.00]], date('Y-m-d'), $userId, null, 'Farmer Sok', '012345678', '');

        $this->assertStringStartsWith('SAL-', $result['reference']);
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$product['id']]);
        $this->assertSame(16, (int) $stmt->fetchColumn());
    }

    // ---- 11. Credit Sale ----

    public function testCreditSaleCreatesExpectedSaleAndDebt(): void
    {
        $product = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];

        $result = recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 4, 'price' => 3.00]], date('Y-m-d'), $userId, null, 'Farmer Sok', '012345678', '');

        $this->assertStringStartsWith('SAL-', $result['reference']);
        $this->assertStringStartsWith('DBT-', $result['debt_reference']);

        $stmt = $this->pdo->prepare("SELECT type FROM stock_transactions WHERE reference = ?");
        $stmt->execute([$result['reference']]);
        $this->assertSame('sale', $stmt->fetchColumn());
    }

    public function testCreditSaleCreatesExactlyOneDebt(): void
    {
        $product = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];
        $countBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM customer_debts')->fetchColumn();

        recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 4, 'price' => 3.00]], date('Y-m-d'), $userId, null, 'Farmer Sok', '012345678', '');

        $this->assertSame($countBefore + 1, (int) $this->pdo->query('SELECT COUNT(*) FROM customer_debts')->fetchColumn());
    }

    public function testCreditSaleTotalIsCorrect(): void
    {
        $product = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];

        $result = recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 4, 'price' => 3.00]], date('Y-m-d'), $userId, null, 'Farmer Sok', '012345678', '');

        $this->assertEqualsWithDelta(12.00, $result['total'], 0.001);
        $stmt = $this->pdo->prepare('SELECT total_amount FROM customer_debts WHERE reference = ?');
        $stmt->execute([$result['debt_reference']]);
        $this->assertEqualsWithDelta(12.00, (float) $stmt->fetchColumn(), 0.001);
    }

    public function testCreditSaleDecrementsStockExactlyOnce(): void
    {
        $product = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];

        recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 4, 'price' => 3.00]], date('Y-m-d'), $userId, null, 'Farmer Sok', '012345678', '');

        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$product['id']]);
        $this->assertSame(16, (int) $stmt->fetchColumn());
    }

    public function testCreditSaleForAnExistingCustomerDoesNotCreateANewCustomerRow(): void
    {
        $product = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];
        $customer = testSeedCustomer($this->pdo);
        $countBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();

        $result = recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 2, 'price' => 3.00]], date('Y-m-d'), $userId, $customer['id'], null, null, '');

        $this->assertSame($customer['id'], $result['customer_id']);
        $this->assertSame($countBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn());
    }

    // ---- 12. Debt Payment ----

    public function testDebtPaymentIncreasesPaidAmountCorrectly(): void
    {
        $debt = $this->seedDebt(100.00);

        recordDebtPayment($this->pdo, $debt['id'], 30.00, date('Y-m-d'), 'first installment', $debt['userId']);

        $stmt = $this->pdo->prepare('SELECT paid_amount FROM customer_debts WHERE id = ?');
        $stmt->execute([$debt['id']]);
        $this->assertEqualsWithDelta(30.00, (float) $stmt->fetchColumn(), 0.001);
    }

    public function testDebtPaymentGeneratedBalanceAndStatusStayCorrect(): void
    {
        $debt = $this->seedDebt(100.00);

        recordDebtPayment($this->pdo, $debt['id'], 30.00, date('Y-m-d'), 'first installment', $debt['userId']);

        $stmt = $this->pdo->prepare('SELECT balance, status FROM customer_debts WHERE id = ?');
        $stmt->execute([$debt['id']]);
        $row = $stmt->fetch();
        $this->assertEqualsWithDelta(70.00, (float) $row['balance'], 0.001);
        $this->assertSame('partially_paid', $row['status']);
    }

    public function testDebtPaymentThatExactlyClearsTheBalanceMarksItPaid(): void
    {
        $debt = $this->seedDebt(50.00);

        recordDebtPayment($this->pdo, $debt['id'], 50.00, date('Y-m-d'), 'paid in full', $debt['userId']);

        $stmt = $this->pdo->prepare('SELECT balance, status FROM customer_debts WHERE id = ?');
        $stmt->execute([$debt['id']]);
        $row = $stmt->fetch();
        $this->assertEqualsWithDelta(0.00, (float) $row['balance'], 0.001);
        $this->assertSame('paid', $row['status']);
    }

    public function testDebtPaymentAppendsToPaymentLedger(): void
    {
        $debt = $this->seedDebt(100.00);

        recordDebtPayment($this->pdo, $debt['id'], 30.00, date('Y-m-d'), 'first installment', $debt['userId']);
        recordDebtPayment($this->pdo, $debt['id'], 20.00, date('Y-m-d'), 'second installment', $debt['userId']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM customer_debt_payments WHERE debt_id = ?');
        $stmt->execute([$debt['id']]);
        $this->assertSame(2, (int) $stmt->fetchColumn());
    }

    // ---- 13. Overpayment ----

    public function testOverpaymentIsRejected(): void
    {
        $debt = $this->seedDebt(50.00);

        $this->expectException(DebtOverpaymentException::class);
        recordDebtPayment($this->pdo, $debt['id'], 50.01, date('Y-m-d'), 'overpay attempt', $debt['userId']);
    }

    public function testOverpaymentDoesNotModifyTheDebt(): void
    {
        $debt = $this->seedDebt(50.00);

        try {
            recordDebtPayment($this->pdo, $debt['id'], 50.01, date('Y-m-d'), 'overpay attempt', $debt['userId']);
        } catch (DebtOverpaymentException $e) {
            // expected
        }

        $stmt = $this->pdo->prepare('SELECT paid_amount FROM customer_debts WHERE id = ?');
        $stmt->execute([$debt['id']]);
        $this->assertEqualsWithDelta(0.00, (float) $stmt->fetchColumn(), 0.001);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM customer_debt_payments WHERE debt_id = ?');
        $stmt->execute([$debt['id']]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no payment row must be recorded for a rejected overpayment');
    }

    public function testOverpaymentAfterAPartialPaymentIsAlsoRejected(): void
    {
        $debt = $this->seedDebt(50.00);
        recordDebtPayment($this->pdo, $debt['id'], 40.00, date('Y-m-d'), 'partial', $debt['userId']);

        $this->expectException(DebtOverpaymentException::class);
        recordDebtPayment($this->pdo, $debt['id'], 10.01, date('Y-m-d'), 'overpay by 1 cent', $debt['userId']);
    }

    /** @return array{id:int, userId:int} */
    private function seedDebt(float $total): array
    {
        $product = testSeedProduct($this->pdo, 100, ['sale_price' => $total]);
        $userId = testSeedUserRole($this->pdo)['id'];
        $result = recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 1, 'price' => $total]], date('Y-m-d'), $userId, null, 'Debt Test Customer', '', '');

        $stmt = $this->pdo->prepare('SELECT id FROM customer_debts WHERE reference = ?');
        $stmt->execute([$result['debt_reference']]);
        return ['id' => (int) $stmt->fetchColumn(), 'userId' => $userId];
    }
}
