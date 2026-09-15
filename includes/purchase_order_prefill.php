<?php
// ================================================
// Phase P3-B2: assisted Draft Purchase Order prefill.
//
// Turns the Low Stock page's supplier-group query string
// (?supplier_id=N&product_id[]=A&product_id[]=B) into the line data
// purchase-order/create.php pre-populates its form with. Pure and
// read-only: takes the $suppliers/$products arrays create.php has
// ALREADY fetched for its own form, never queries the database itself,
// never touches a superglobal - so it is directly unit-testable and
// adds no N+1 lookup. Same "one concern, one small file" convention as
// csrf.php / currency.php / sortable.php / validation.php.
//
// SECURITY: every value here arrives from the query string and is
// therefore attacker-controlled. Nothing is trusted:
//   - supplier_id must be a digit string naming a supplier that exists.
//   - each product_id must be a digit string naming a product that
//     exists AND whose OWN products.supplier_id equals that supplier.
//     A client cannot make this function suggest supplier B's product
//     under supplier A, no matter what it puts in the URL.
//   - anything failing a check is silently dropped, leaving the form in
//     the state it would have had without that parameter - the exact
//     convention stock-in/index.php's own ?product_id= preselect
//     (Phase L1) already established for a tampered/unknown id.
//
// This is a PREFILL convenience only. It deliberately does NOT impose a
// product-belongs-to-supplier rule on purchase orders in general:
// createPurchaseOrder() is untouched, and manually adding any product
// to any supplier's PO (a secondary-supplier purchase) keeps working
// exactly as it does today. The rule enforced here is narrower - "the
// assisted flow must never SUGGEST a line the Low Stock page could not
// legitimately have offered".
// ================================================

// $query: the raw $_GET array (or any equivalent map).
// $suppliers/$products: rows as create.php already loaded them
//   (SELECT * FROM suppliers / products).
// $maxLines: hard cap on how many lines one request may prefill - a
//   cheap guard against a hand-crafted URL carrying thousands of ids;
//   far above any real supplier's low-stock count, so it never fires in
//   normal use.
//
// Returns ['supplier_id' => ?int, 'lines' => array], where each line is
// ['product_id' => int, 'qty' => string, 'cost' => string]:
//   - qty is the product's reorder_quantity when that is a positive
//     integer, and '' (blank, for the user to type) when it is NULL, 0,
//     or - defensively, since the schema's CHECK already forbids it -
//     negative. A blank qty is deliberate: create.php's own validation
//     rejects a non-positive ordered_qty, so prefilling 0 would only
//     hand the user a line guaranteed to fail.
//   - cost is the product's current cost_price, a DEFAULT only; the
//     form leaves it fully editable, and the server recomputes the
//     authoritative value from what is actually submitted.
function buildAssistedPoPrefill(array $query, array $suppliers, array $products, int $maxLines = 100): array
{
    $none = ['supplier_id' => null, 'lines' => []];

    $rawSupplier = $query['supplier_id'] ?? null;
    if (!is_string($rawSupplier) && !is_int($rawSupplier)) {
        return $none;
    }
    $rawSupplier = (string) $rawSupplier;
    if ($rawSupplier === '' || !ctype_digit($rawSupplier)) {
        return $none;
    }
    $supplierId = (int) $rawSupplier;

    $supplierExists = false;
    foreach ($suppliers as $supplier) {
        if ((int) $supplier['id'] === $supplierId) {
            $supplierExists = true;
            break;
        }
    }
    if (!$supplierExists) {
        return $none;
    }

    // A valid supplier is honored even when no line survives validation:
    // the user did choose that supplier, so pre-selecting it is helpful
    // and carries no risk. They simply get an empty line table to fill
    // in, exactly as if they had opened the page and picked the
    // supplier by hand.
    $result = ['supplier_id' => $supplierId, 'lines' => []];

    $rawProductIds = $query['product_id'] ?? [];
    if (!is_array($rawProductIds)) {
        $rawProductIds = [$rawProductIds];
    }

    $productsById = [];
    foreach ($products as $product) {
        $productsById[(int) $product['id']] = $product;
    }

    $seen = [];
    foreach ($rawProductIds as $rawProductId) {
        if (count($result['lines']) >= $maxLines) {
            break;
        }
        // A nested array (?product_id[][]=1) lands here as an array and
        // is rejected rather than triggering a string conversion notice.
        if (!is_string($rawProductId) && !is_int($rawProductId)) {
            continue;
        }
        $rawProductId = (string) $rawProductId;
        if ($rawProductId === '' || !ctype_digit($rawProductId)) {
            continue;
        }
        $productId = (int) $rawProductId;

        // Repeated ids collapse to one line - a duplicated id must never
        // become two suggestions for the same product.
        if (isset($seen[$productId]) || !isset($productsById[$productId])) {
            continue;
        }
        $product = $productsById[$productId];

        // The ownership check this whole function exists for.
        if ($product['supplier_id'] === null || (int) $product['supplier_id'] !== $supplierId) {
            continue;
        }
        $seen[$productId] = true;

        $qty = '';
        if ($product['reorder_quantity'] !== null && (int) $product['reorder_quantity'] > 0) {
            $qty = (string) (int) $product['reorder_quantity'];
        }

        $result['lines'][] = [
            'product_id' => $productId,
            'qty' => $qty,
            'cost' => $product['cost_price'] !== null ? (string) $product['cost_price'] : '',
        ];
    }

    return $result;
}
