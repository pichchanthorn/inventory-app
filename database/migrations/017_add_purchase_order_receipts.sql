-- ================================================
-- Migration 017: Purchase Order Receiving (Phase P2) - receipt linkage
-- table only. Schema addition, no application code lives in this file.
--
-- purchase_order_receipts links one purchase_order_items row to the
-- specific stock_transaction_items row that fulfilled it, one row per
-- receiving event per line. This is what makes "how much of this PO
-- line came from which delivery" recoverable once a line has been
-- received more than once (partial receiving) - without it, only the
-- current cumulative purchase_order_items.received_qty would exist,
-- with no way to reconstruct the individual receiving events that
-- produced it.
--
-- purchase_order_item_id deliberately has NO uniqueness constraint - one
-- PO line legitimately accumulates many receipt rows across partial
-- deliveries (that is the entire point of this table). UNIQUE on
-- stock_transaction_item_id, however, IS enforced: in this design, a
-- single receiving-form line is always sourced from at most one PO line
-- (see includes/purchase_order.php's receivePurchaseOrder() - the
-- receiving form never merges two different PO lines into one Stock In
-- line), so a given stock_transaction_items row can only ever be "the
-- receipt for" one purchase_order_items row. This is a data-integrity
-- backstop (same "application code is the primary guard, this is just a
-- backstop" spirit as every other CHECK/UNIQUE in this schema), not the
-- primary duplicate-prevention mechanism - that is the receiving
-- idempotency token plus the guarded received_qty UPDATE (see
-- receivePurchaseOrder()'s own header comment).
--
-- product_id/purchase_order_id are deliberately NOT duplicated onto this
-- table - both are reachable via the two FKs (purchase_order_item_id ->
-- purchase_order_items.product_id, and .purchase_order_id), avoiding a
-- third source of truth for data this table can already derive by join.
--
-- No ON DELETE clause on either FK (defaults to RESTRICT) - a receipt
-- row is real inventory-movement history and must never be silently
-- destroyed by deleting its parent PO line or the Stock In transaction
-- item that produced it. In practice neither is ever deletable once a
-- receipt exists: purchase_order_items has no delete path of its own
-- (only cascades when its whole PO is deleted, and deletePurchaseOrder()
-- is already draft-only - a PO with any receipt is never a draft), and
-- stock_transaction_items has no delete path anywhere in this app.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include this
-- table.
-- ================================================

USE inventory_db;

CREATE TABLE purchase_order_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_item_id INT NOT NULL,
    stock_transaction_item_id INT NOT NULL,
    qty INT NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id),
    FOREIGN KEY (stock_transaction_item_id) REFERENCES stock_transaction_items(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_purchase_order_item (purchase_order_item_id),
    UNIQUE KEY uq_por_stock_transaction_item (stock_transaction_item_id),
    CONSTRAINT chk_por_qty_positive CHECK (qty > 0)
);
