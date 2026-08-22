<?php
// Shared receipt card, rendered by both pos/index.php (right after a
// sale completes, via the session-flash PRG) and pos/receipt.php (looking
// up any past sale by reference) - one source of markup so the two views
// can't drift apart.
//
// Expects $sale in scope:
//   reference, date, cashier (string|null), lines (array of
//   name/sku/qty/price/subtotal), total, cash_received (float|null),
//   change_due (float|null) - null means "not recorded" (pre-migration
//   row), not zero, and is rendered as such rather than as $0.00.
//   khr_total (float, optional) - approximate Riel total (USD total x the
//   Admin-configured rate), computed by the caller. Purely additive: only
//   rendered when the key is present, changes nothing else on the card.
//
// Expects $receiptSecondaryAction (optional): a pre-rendered HTML string
// for the one page-specific action button next to the universal Print
// Receipt button (e.g. "New Sale" on POS, "Back to Stock Reports" on the
// lookup page). Left empty renders Print Receipt alone.
?>
<div class="card p-4" id="posReceipt">
  <div class="text-center mb-3">
    <div class="bracket-label mb-1"><?= __('pos_receipt_title') ?></div>
    <div class="fs-4 mono fw-bold text-primary"><?= htmlspecialchars($sale['reference']) ?></div>
    <div class="text-secondary small mono"><?= htmlspecialchars($sale['date']) ?></div>
    <div class="text-secondary small mt-1"><?= __('pos_cashier_label') ?>: <?= htmlspecialchars($sale['cashier'] ?? '—') ?></div>
  </div>
  <table class="table table-sm mb-3">
    <thead class="table-light">
      <tr><th><?= __('common_product') ?></th><th class="text-end"><?= __('common_qty') ?></th><th class="text-end"><?= __('stockout_unit_price') ?></th><th class="text-end"><?= __('pos_line_total') ?></th></tr>
    </thead>
    <tbody>
      <?php foreach ($sale['lines'] as $line): ?>
      <tr>
        <td><?= htmlspecialchars($line['name']) ?> <span class="slug-pill"><?= htmlspecialchars($line['sku']) ?></span></td>
        <td class="text-end mono"><?= $line['qty'] ?></td>
        <td class="text-end mono">$<?= number_format($line['price'], 2) ?></td>
        <td class="text-end mono">$<?= number_format($line['subtotal'], 2) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="border-top pt-3">
    <div class="d-flex justify-content-between py-1 fs-5">
      <span class="fw-bold"><?= __('pos_total_label') ?></span>
      <span class="mono fw-bold">$<?= number_format($sale['total'], 2) ?></span>
    </div>
    <?php if (isset($sale['khr_total'])): ?>
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary"><?= __('pos_total_khr_label') ?></span>
      <span class="mono">៛<?= number_format($sale['khr_total'], 0) ?></span>
    </div>
    <?php endif; ?>
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary"><?= __('pos_cash_received_label') ?></span>
      <span class="mono"><?= $sale['cash_received'] !== null ? '$' . number_format($sale['cash_received'], 2) : __('pos_not_recorded') ?></span>
    </div>
    <div class="d-flex justify-content-between py-1">
      <span class="text-secondary"><?= __('pos_change_due_label') ?></span>
      <span class="mono fw-bold" style="color:var(--good);"><?= $sale['change_due'] !== null ? '$' . number_format($sale['change_due'], 2) : __('pos_not_recorded') ?></span>
    </div>
  </div>
  <div class="d-flex gap-2 mt-4 no-print">
    <button type="button" class="btn btn-outline-primary flex-fill" onclick="window.print()"><i class="bi bi-printer"></i> <?= __('pos_print_button') ?></button>
    <?= $receiptSecondaryAction ?? '' ?>
  </div>
</div>
