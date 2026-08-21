<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['lang']) || !in_array($_SESSION['lang'], ['en', 'km'], true)) {
    $_SESSION['lang'] = 'en';
}
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'km'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}
$GLOBALS['__translations'] = require __DIR__ . '/../lang/' . $_SESSION['lang'] . '.php';

function __($key) {
    return $GLOBALS['__translations'][$key] ?? $key;
}

// PHP's date() has no built-in Khmer locale, and Khmer doesn't have a
// standard abbreviated month form the way English does ("Jan", "Feb", ...),
// so this only localizes the month-name portion (format char 'M') via a
// manual lookup, then hands the rest of the format string to date() as
// usual. English is a pure pass-through — zero behavior change.
function localizedDate(string $format, int $timestamp): string {
    if ($_SESSION['lang'] !== 'km' || strpos($format, 'M') === false) {
        return date($format, $timestamp);
    }
    $khmerMonths = ['មករា', 'កុម្ភៈ', 'មីនា', 'មេសា', 'ឧសភា', 'មិថុនា', 'កក្កដា', 'សីហា', 'កញ្ញា', 'តុលា', 'វិច្ឆិកា', 'ធ្នូ'];
    $month = $khmerMonths[(int) date('n', $timestamp) - 1];
    $rest = trim(str_replace('M', '', $format));
    return trim($month . ' ' . ($rest !== '' ? date($rest, $timestamp) : ''));
}
