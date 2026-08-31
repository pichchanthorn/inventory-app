<?php
// Shared receipt/invoice card, rendered by both pos/index.php (right after
// a sale completes, via the session-flash PRG) and pos/receipt.php (looking
// up any past sale by reference) - one source of markup so the two views
// can't drift apart.
//
// Expects $sale in scope:
//   reference, date, cashier (string|null), lines (array of
//   name/sku/package/qty/price/subtotal - package may be '' or null),
//   total, cash_received (float|null), change_due (float|null) - null
//   means "not recorded" (pre-migration row) UNLESS is_credit is true, in
//   which case null instead means "no cash changed hands, this is a
//   credit sale" - a different reason for the same null, rendered with
//   different messaging below.
//   khr_total (float, optional) - approximate Riel total (USD total x the
//   Admin-configured rate), computed by the caller. Purely additive: only
//   rendered when the key is present, changes nothing else on the card.
//
//   is_credit (bool, optional - Batch 2 of the Debt/Customer Credit
//   feature), customer_name (string, when is_credit), customer_phone
//   (string|null, when is_credit), due_date (string|null, when
//   is_credit), debt_reference (string, when is_credit), paid_amount
//   (float, when is_credit), balance (float, when is_credit),
//   debt_status ('open'|'partially_paid'|'paid', when is_credit) - when
//   is_credit is true, the invoice shows Customer/Payment/Status/Paid/
//   Balance instead of Cash Received/Change Due, since no cash changed
//   hands at the point of sale.
//
// Expects $business in scope (array|false): business_name,
//   business_address, business_phone, business_email - the app_settings
//   singleton row's shop-identity columns, fetched by the caller (same
//   "caller computes, partial renders" convention as khr_total above).
//   Any blank/missing field is simply omitted from the invoice header;
//   false (no app_settings row at all yet) renders no business info,
//   same as every field being blank.
//
// Expects $receiptSecondaryAction (optional): a pre-rendered HTML string
// for the one page-specific action button next to the universal Print
// Receipt button (e.g. "New Sale" on POS, "Back to Stock Reports" on the
// lookup page). Left empty renders Print Receipt alone.
$business = $business ?: [];
$isCredit = !empty($sale['is_credit']);
if ($isCredit) {
    $debtStatus = $sale['debt_status'] ?? 'open';
    $statusClass = $debtStatus === 'paid' ? 'badge-normal' : ($debtStatus === 'partially_paid' ? 'badge-warn' : 'badge-low');
    $statusText = $debtStatus === 'paid' ? __('invoice_status_paid') : ($debtStatus === 'partially_paid' ? __('invoice_status_partial') : __('invoice_status_unpaid'));
} else {
    $statusClass = 'badge-normal';
    $statusText = __('invoice_status_paid');
}
?>
<div class="card p-4 invoice-card" id="posReceipt">

  <!-- Invoice header: logo + shop identity + document title -->
  <div class="text-center mb-3 invoice-header">
    <img src="<?= BASE_URL ?>/assets/logo-192.png" alt="" width="44" height="44" class="mb-2">
    <?php if (!empty($business['business_name'])): ?>
      <div class="fw-bold fs-5"><?= htmlspecialchars($business['business_name']) ?></div>
    <?php endif; ?>
    <?php if (!empty($business['business_address'])): ?>
      <div class="text-secondary small"><?= htmlspecialchars($business['business_address']) ?></div>
    <?php endif; ?>
    <?php if (!empty($business['business_phone'])): ?>
      <div class="text-secondary small"><?= __('common_phone') ?>: <?= htmlspecialchars($business['business_phone']) ?></div>
    <?php endif; ?>
    <?php if (!empty($business['business_email'])): ?>
      <div class="text-secondary small"><?= __('common_email') ?>: <?= htmlspecialchars($business['business_email']) ?></div>
    <?php endif; ?>
    <div class="bracket-label mt-2"><?= __('invoice_title') ?></div>
  </div>

  <!-- Invoice info: reference, date, cashier, customer, payment, status -->
  <div class="invoice-info border-top border-bottom py-2 mb-3">
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary"><?= __('invoice_number_label') ?></span>
      <span class="mono fw-bold text-primary"><?= htmlspecialchars($sale['reference']) ?></span>
    </div>
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary"><?= __('common_date') ?></span>
      <span class="mono"><?= htmlspecialchars(localizedDate('M j, Y', strtotime($sale['date']))) ?></span>
    </div>
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary"><?= __('pos_cashier_label') ?></span>
      <span><?= htmlspecialchars($sale['cashier'] ?? '—') ?></span>
    </div>
    <?php if ($isCredit): ?>
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary"><?= __('pos_customer_label') ?></span>
      <span><?= htmlspecialchars($sale['customer_name'] ?? '—') ?></span>
    </div>
    <?php if (!empty($sale['customer_phone'])): ?>
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary"><?= __('common_phone') ?></span>
      <span class="mono"><?= htmlspecialchars($sale['customer_phone']) ?></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary"><?= __('pos_payment_section_title') ?></span>
      <span><?= $isCredit ? __('pos_payment_credit') : __('pos_payment_cash') ?></span>
    </div>
    <div class="d-flex justify-content-between align-items-center py-1">
      <span class="text-secondary"><?= __('invoice_status_label') ?></span>
      <span class="badge-stock <?= $statusClass ?>"><?= $statusText ?></span>
    </div>
    <?php if ($isCredit && !empty($sale['due_date'])): ?>
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary"><?= __('pos_due_date_label') ?></span>
      <span class="mono"><?= htmlspecialchars(localizedDate('M j, Y', strtotime($sale['due_date']))) ?></span>
    </div>
    <?php endif; ?>
  </div>

  <div class="table-responsive">
    <table class="table table-sm mb-3">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th><?= __('common_product') ?></th>
          <th><?= __('invoice_col_package') ?></th>
          <th class="text-end"><?= __('common_qty') ?></th>
          <th class="text-end"><?= __('stockout_unit_price') ?></th>
          <th class="text-end"><?= __('pos_line_total') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sale['lines'] as $i => $line): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= htmlspecialchars($line['name']) ?> <span class="slug-pill"><?= htmlspecialchars($line['sku']) ?></span></td>
          <td class="text-secondary"><?= !empty($line['package']) ? htmlspecialchars($line['package']) : '—' ?></td>
          <td class="text-end mono"><?= $line['qty'] ?></td>
          <td class="text-end mono">$<?= number_format($line['price'], 2) ?></td>
          <td class="text-end mono">$<?= number_format($line['subtotal'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="border-top pt-3">
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary"><?= __('pos_subtotal_label') ?></span>
      <span class="mono">$<?= number_format($sale['total'], 2) ?></span>
    </div>
    <div class="d-flex justify-content-between py-1 fs-5">
      <span class="fw-bold"><?= __('invoice_grand_total_label') ?></span>
      <span class="mono fw-bold">$<?= number_format($sale['total'], 2) ?></span>
    </div>
    <?php if (isset($sale['khr_total'])): ?>
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary"><?= __('pos_total_khr_label') ?></span>
      <span class="mono">៛<?= number_format($sale['khr_total'], 0) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($isCredit): ?>
    <div class="d-flex justify-content-between py-1 mt-2">
      <span class="text-secondary"><?= __('invoice_paid_label') ?></span>
      <span class="mono">$<?= number_format($sale['paid_amount'] ?? 0, 2) ?></span>
    </div>
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary fw-bold"><?= __('invoice_balance_label') ?></span>
      <span class="mono fw-bold" style="color:var(--warn);">$<?= number_format($sale['balance'] ?? $sale['total'], 2) ?></span>
    </div>
    <?php if (!empty($sale['debt_reference'])): ?>
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary"><?= __('pos_debt_reference_label') ?></span>
      <span class="mono"><?= htmlspecialchars($sale['debt_reference']) ?></span>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="d-flex justify-content-between py-1 mt-2">
      <span class="text-secondary"><?= __('pos_cash_received_label') ?></span>
      <span class="mono"><?= $sale['cash_received'] !== null ? '$' . number_format($sale['cash_received'], 2) : __('pos_not_recorded') ?></span>
    </div>
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary"><?= __('pos_change_due_label') ?></span>
      <span class="mono fw-bold" style="color:var(--good);"><?= $sale['change_due'] !== null ? '$' . number_format($sale['change_due'], 2) : __('pos_not_recorded') ?></span>
    </div>
    <?php endif; ?>
  </div>

  <div class="text-center mt-4 pt-3 border-top invoice-footer">
    <div class="small text-secondary mb-3"><?= __('invoice_footer_thanks') ?></div>
    <div class="d-flex justify-content-between">
      <span class="small text-secondary"><?= __('pos_cashier_label') ?>: <?= htmlspecialchars($sale['cashier'] ?? '—') ?></span>
      <span class="small text-secondary"><?= __('invoice_signature_label') ?>: ____________________</span>
    </div>
  </div>

  <div class="d-flex gap-2 mt-4 no-print">
    <button type="button" class="btn btn-outline-primary flex-fill" onclick="window.print()"><i class="bi bi-printer"></i> <?= __('pos_print_button') ?></button>
    <?= $receiptSecondaryAction ?? '' ?>
  </div>
</div>
