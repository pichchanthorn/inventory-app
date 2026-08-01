<?php
// Works out the URL prefix for this app automatically.
// If the project sits at http://localhost/inventory-app/, BASE_URL becomes "/inventory-app".
// If it sits at the domain root, BASE_URL becomes "" (empty string).
if (!defined('BASE_URL')) {
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    $appRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    define('BASE_URL', str_replace($docRoot, '', $appRoot));
}

// Cache-busting version for assets/style.css — changes automatically whenever
// the file is edited, so browsers always fetch the latest CSS instead of a
// stale cached copy.
if (!defined('ASSET_VER')) {
    define('ASSET_VER', @filemtime(dirname(__DIR__) . '/assets/style.css') ?: time());
}
