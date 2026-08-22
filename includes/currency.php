<?php
// ================================================
// Shared USD<->KHR conversion for price/cost entry fields (Product
// Cost/Sale Price, Stock In Unit Cost). Single authoritative rounding
// point - only this function's result is ever stored, so a live JS
// preview elsewhere can never disagree with what actually gets saved.
//
// Separate from includes/receipt_view.php's khr_total (the read-only
// KHR *display* feature) - this is the write path, converting a
// staff-entered number into the USD value that lands in cost_price/
// sale_price/unit_price columns. Never trusts client-side conversion:
// the client only ever renders a live preview: the raw amount plus a
// currency flag are what actually get submitted and resolved here.
// ================================================

class PriceConversionException extends RuntimeException {}

// Resolves one submitted price/cost field - a raw amount plus a
// parallel "<field>_currency" flag ('USD'|'KHR'), both read from
// $source (a $_POST array, or one Stock In line's data) - to the USD
// value that gets stored.
//
// Throws PriceConversionException, never silently falls back to
// treating a KHR amount as USD, when:
//  - currency is 'KHR' but no rate is configured ($rate is null) - the
//    UI is expected to disable the KHR toggle in this case, so this
//    only fires on a tampered/unexpected request.
//  - the converted result rounds to $0.00 from a genuinely non-zero raw
//    amount (e.g. "3" typed instead of "3000" Riel) - a rounding result
//    this large is a data-entry mistake, not the ordinary off-by-a-cent
//    rounding that's expected and fine to leave undocumented-as-a-bug.
function resolvePriceField(array $source, string $field, ?float $rate): float {
    $raw = (float) ($source[$field] ?? 0);
    $currency = ($source[$field . '_currency'] ?? 'USD') === 'KHR' ? 'KHR' : 'USD';

    if ($currency === 'KHR') {
        if (!$rate) {
            throw new PriceConversionException(__('currency_err_no_rate_configured'));
        }
        $usd = round($raw / $rate, 2);
    } else {
        $usd = round($raw, 2);
    }

    if ($raw > 0 && $usd <= 0) {
        throw new PriceConversionException(__('currency_err_price_rounds_to_zero'));
    }

    return $usd;
}
