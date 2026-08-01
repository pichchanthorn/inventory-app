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
