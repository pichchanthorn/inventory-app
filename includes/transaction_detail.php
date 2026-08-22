<?php
// Shared read-only detail card for Stock In / Stock Out / Adjustment
// transactions - the Gap-1 equivalent of includes/receipt_view.php for
// Sale, but deliberately a SEPARATE file. receipt_view.php (and the POS
// sale flow that renders it) stays untouched: Sale has cashier/cash-
// received/change-due fields that don't apply here, and this feature is
// explicitly not allowed to touch the receipt view.
//
// Expects $tx in scope:
//   reference, type ('in'|'out'|'adjustment'), title (translated string),
//   date, performed_by (string|null), supplier (string|null - 'in' only),
//   note (string|null - free note for 'in'/'out', reason for
//   'adjustment'), show_prices (bool - false for 'adjustment'),
//   lines (array of name/sku/package_size/qty/unit_price/subtotal),
//   total (meaningless when show_prices is false, not rendered then).
//
// Adjustment lines carry only a magnitude (qty), never a signed
// before/after change - adjustStock() only ever persisted the absolute
// delta, so there's nothing more precise to show here. That's a known,
// separate data-model gap, not something this view can fix without
// touching adjustStock() (frozen).
//
// Expects $txDetailSecondaryAction (optional): pre-rendered HTML for the
// one page-specific action button next to the universal Print button,
// same convention as receipt_view.php's $receiptSecondaryAction.
?>
<div class="card p-4" id="txDetailCard">
  <div class="text-center mb-3">
    <div class="bracket-label mb-1"><?= htmlspecialchars($tx['title']) ?></div>
    <div class="fs-4 mono fw-bold text-primary"><?= htmlspecialchars($tx['reference']) ?></div>
    <div class="text-secondary small mono"><?= htmlspecialchars($tx['date']) ?></div>
    <div class="text-secondary small mt-1"><?= __('txdetail_performed_by') ?>: <?= htmlspecialchars($tx['performed_by'] ?? '—') ?></div>
    <?php if (!empty($tx['supplier'])): ?>
    <div class="text-secondary small"><?= __('common_supplier') ?>: <?= htmlspecialchars($tx['supplier']) ?></div>
    <?php endif; ?>
    <?php if (!empty($tx['note'])): ?>
    <div class="text-secondary small"><?= $tx['type'] === 'adjustment' ? __('stockadj_reason_label_plain') : __('common_note') ?>: <?= htmlspecialchars($tx['note']) ?></div>
    <?php endif; ?>
  </div>
  <table class="table table-sm mb-3">
    <thead class="table-light">
      <tr>
        <th><?= __('common_product') ?></th>
        <th class="text-end"><?= $tx['show_prices'] ? __('common_qty') : __('txdetail_qty_changed') ?></th>
        <?php if ($tx['show_prices']): ?>
        <th class="text-end"><?= __('stockout_unit_price') ?></th>
        <th class="text-end"><?= __('pos_line_total') ?></th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tx['lines'] as $line): ?>
      <tr>
        <td>
          <?= htmlspecialchars($line['name']) ?> <span class="slug-pill"><?= htmlspecialchars($line['sku']) ?></span>
          <?php if (!empty($line['package_size'])): ?><span class="text-secondary small ms-1"><?= htmlspecialchars($line['package_size']) ?></span><?php endif; ?>
        </td>
        <td class="text-end mono"><?= $line['qty'] ?></td>
        <?php if ($tx['show_prices']): ?>
        <td class="text-end mono">$<?= number_format($line['unit_price'], 2) ?></td>
        <td class="text-end mono">$<?= number_format($line['subtotal'], 2) ?></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($tx['show_prices']): ?>
  <div class="border-top pt-3">
    <div class="d-flex justify-content-between py-1 fs-5">
      <span class="fw-bold"><?= __('pos_total_label') ?></span>
      <span class="mono fw-bold">$<?= number_format($tx['total'], 2) ?></span>
    </div>
  </div>
  <?php endif; ?>
  <div class="d-flex gap-2 mt-4 no-print">
    <button type="button" class="btn btn-outline-primary flex-fill" onclick="window.print()"><i class="bi bi-printer"></i> <?= __('txdetail_print_button') ?></button>
    <?= $txDetailSecondaryAction ?? '' ?>
  </div>
</div>
