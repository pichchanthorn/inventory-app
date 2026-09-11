<?php
// ================================================
// Low Stock Alert / Reorder Management (Phase L1) - shared, read-only
// severity classification. Extracted here because the same three-way
// CRITICAL/LOW/NORMAL decision is now needed by four call sites
// (dashboard.php's KPI, product/index.php's list badge, stock-report/
// index.php's By Product tab, stock-alert/index.php's own page) -
// exactly the "meaningfully reduces duplicated logic" case, not
// speculative architecture.
//
// Pure and read-only: never queries the database itself, never writes
// anything, never touches product_batches - current_stock is already
// authoritative for both tracked and untracked products (kept equal to
// SUM(product_batches.qty_on_hand) for a tracked product by every K1-K4-6
// mutation path), so recomputing from batches here would be redundant
// and could only ever diverge from current_stock in the presence of a
// DIFFERENT, pre-existing invariant bug - not something this read-only
// feature should paper over by inventing its own second computation.
//
// Formula (approved design):
//   CRITICAL: current_stock = 0 (regardless of min_stock, including
//             min_stock = 0 - zero stock is always worth surfacing)
//   LOW:      current_stock > 0 AND current_stock <= min_stock
//             (inclusive boundary - current_stock = min_stock is LOW,
//             matching the existing current_stock <= min_stock condition
//             already shipped in dashboard.php/product/index.php/
//             stock-report/index.php long before this phase)
//   NORMAL:   current_stock > min_stock
//
// CRITICAL union LOW is exactly equivalent to the pre-existing
// current_stock <= min_stock check - this is a strict refinement of
// that check, not a new or different condition. min_stock = 0 does NOT
// disable alerting; it means only current_stock = 0 can ever be
// flagged for that product (see the design audit for why this existing,
// already-shipped semantics is preserved rather than special-cased).
//
// reorder_quantity plays no part in this function at all - it is
// informational only and must never affect classification.
function lowStockTier(int $currentStock, int $minStock): string
{
    if ($currentStock === 0) {
        return 'critical';
    }
    if ($currentStock <= $minStock) {
        return 'low';
    }
    return 'normal';
}
