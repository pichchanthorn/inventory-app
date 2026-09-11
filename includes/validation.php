<?php
// ================================================
// Shared, generic input-validation helpers. Deliberately not a
// framework/service layer - one small pure function per concern, the
// same "one concern, one small file" convention as csrf.php/currency.php/
// sortable.php.
// ================================================

// True non-negative integer string (no sign, no decimal point, no
// leading/trailing junk) - deliberately NOT (int) $raw, which would
// silently truncate "5.7" to 5 or "-1" to -1 without ever rejecting it.
// Callers should trim() their raw POST value first if surrounding
// whitespace should be tolerated - ctype_digit() itself rejects a string
// containing any whitespace, so " 5"/"5 " are both invalid here.
//
// Introduced in Phase K4-6-2 (stock-adjustment/index.php's batch target
// quantity / expected_qty), extracted here in Phase L1 so
// product/index.php's min_stock/reorder_quantity validation reuses the
// exact same rule rather than a second, independently-written copy of
// it.
function isNonNegativeIntegerString(string $raw): bool
{
    return $raw !== '' && ctype_digit($raw);
}
