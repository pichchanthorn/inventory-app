<?php
// Works out the URL prefix for this app automatically.
// If the project sits at http://localhost/inventory-app/, BASE_URL becomes "/inventory-app".
// If it sits at the domain root, BASE_URL becomes "" (empty string).
if (!defined('BASE_URL')) {
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    $appRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    define('BASE_URL', str_replace($docRoot, '', $appRoot));
}
