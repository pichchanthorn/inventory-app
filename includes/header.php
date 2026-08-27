<?php
require_once __DIR__ . '/../config/base_url.php';
// $activePage should be set by the including page, e.g. $activePage = 'category';
$activePage = $activePage ?? '';
function navClass($page, $active) {
    return $page === $active ? 'nav-link active fw-semibold' : 'nav-link text-secondary';
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($_SESSION['lang']) ?>">
<head>
<script>
  if (localStorage.getItem('theme') === 'light') {
    document.documentElement.classList.add('theme-light-pending');
  }
</script>
<meta charset="UTF-8">
<title><?= __('app_title') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>/assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="64x64" href="<?= BASE_URL ?>/assets/favicon-64.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css?v=<?= ASSET_VER ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>
<style>
  .sidebar { min-height:100vh; }
  .sidebar .nav-link { padding:.5rem 1rem; margin-bottom:2px; }
</style>
</head>
<body lang="<?= $_SESSION['lang'] ?>">
<script>
  if (document.documentElement.classList.contains('theme-light-pending')) {
    document.body.classList.add('theme-light');
  }
</script>
<div class="d-flex">
  <!-- SIDEBAR
       offcanvas-md: a normal static in-flow sidebar at >=768px (Bootstrap's
       own CSS neutralizes the fixed-position/backdrop/header behavior at
       that breakpoint - identical to the old plain <nav>, same .sidebar
       class and width kept below). Below 768px it becomes a real
       Bootstrap offcanvas: fixed, hidden by default, slide-in with a
       backdrop, closeable via the header's close button, backdrop click,
       or Esc - all through data-bs-* attributes, no custom JS. -->
  <nav class="sidebar offcanvas-md offcanvas-start" style="width:230px;" tabindex="-1" id="appSidebar" aria-labelledby="appSidebarLabel">
    <div class="offcanvas-header d-md-none">
      <span class="offcanvas-title visually-hidden" id="appSidebarLabel"><?= __('app_brand_name') ?></span>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="<?= __('common_close') ?>"></button>
    </div>
    <div class="offcanvas-body p-3 d-flex flex-column">
    <div class="d-flex align-items-center gap-2 mb-4">
      <img src="<?= BASE_URL ?>/assets/logo-192.png" alt="" width="26" height="26">
      <span class="fs-5 fw-bold"><?= __('app_brand_name') ?></span>
    </div>

    <div class="sidebar-section"><?= __('nav_overview') ?></div>
    <a class="<?= navClass('dashboard', $activePage) ?>" href="<?= BASE_URL ?>/dashboard.php"><i class="bi bi-speedometer2 me-2"></i><?= __('nav_dashboard') ?></a>

    <div class="sidebar-section"><?= __('nav_catalog') ?></div>
    <a class="<?= navClass('product', $activePage) ?>" href="<?= BASE_URL ?>/product/index.php"><i class="bi bi-box me-2"></i><?= __('nav_products') ?></a>
    <a class="<?= navClass('category', $activePage) ?>" href="<?= BASE_URL ?>/category/index.php"><i class="bi bi-tags me-2"></i><?= __('nav_categories') ?></a>
    <a class="<?= navClass('unit', $activePage) ?>" href="<?= BASE_URL ?>/unit/index.php"><i class="bi bi-rulers me-2"></i><?= __('nav_units') ?></a>
    <a class="<?= navClass('supplier', $activePage) ?>" href="<?= BASE_URL ?>/supplier/index.php"><i class="bi bi-truck me-2"></i><?= __('nav_suppliers') ?></a>

    <div class="sidebar-section"><?= __('nav_operation') ?></div>
    <a class="<?= navClass('pos', $activePage) ?>" href="<?= BASE_URL ?>/pos/index.php"><i class="bi bi-cash-register me-2"></i><?= __('nav_pos') ?></a>
    <a class="<?= navClass('stock-in', $activePage) ?>" href="<?= BASE_URL ?>/stock-in/index.php"><i class="bi bi-download me-2"></i><?= __('nav_stock_in') ?></a>
    <a class="<?= navClass('stock-out', $activePage) ?>" href="<?= BASE_URL ?>/stock-out/index.php"><i class="bi bi-upload me-2"></i><?= __('nav_stock_out') ?></a>
    <a class="<?= navClass('stock-adjustment', $activePage) ?>" href="<?= BASE_URL ?>/stock-adjustment/index.php"><i class="bi bi-arrow-repeat me-2"></i><?= __('nav_stock_adjustments') ?></a>

    <div class="sidebar-section"><?= __('nav_reports') ?></div>
    <a class="<?= navClass('stock-report', $activePage) ?>" href="<?= BASE_URL ?>/stock-report/index.php"><i class="bi bi-bar-chart me-2"></i><?= __('nav_stock_reports') ?></a>

    <?php if (function_exists('isAdmin') && isAdmin()): ?>
    <div class="sidebar-section"><?= __('nav_administration') ?></div>
    <a class="<?= navClass('user', $activePage) ?>" href="<?= BASE_URL ?>/user/index.php"><i class="bi bi-people me-2"></i><?= __('nav_users') ?></a>
    <a class="<?= navClass('settings', $activePage) ?>" href="<?= BASE_URL ?>/settings/index.php"><i class="bi bi-sliders me-2"></i><?= __('nav_settings') ?></a>
    <a class="<?= navClass('audit', $activePage) ?>" href="<?= BASE_URL ?>/audit/index.php"><i class="bi bi-clock-history me-2"></i><?= __('nav_audit_log') ?></a>
    <?php endif; ?>

    <div class="sidebar-section"><?= __('nav_account') ?></div>
    <a class="<?= navClass('profile', $activePage) ?>" href="<?= BASE_URL ?>/profile.php"><i class="bi bi-person-circle me-2"></i><?= __('nav_profile') ?></a>
    <button type="button" class="theme-toggle-btn" onclick="toggleTheme()">
      <i class="bi bi-circle-half"></i> <span id="themeToggleLabel"><?= __('theme_toggle_dark') ?></span>
    </button>
    <a href="?lang=<?= $_SESSION['lang'] === 'km' ? 'en' : 'km' ?>" class="theme-toggle-btn text-decoration-none d-block text-center">
      <?= $_SESSION['lang'] === 'km' ? 'EN' : 'ខ្មែរ' ?>
    </a>
    <a class="nav-link text-secondary" href="<?= BASE_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-left me-2"></i><?= __('nav_sign_out') ?></a>
    </div>
  </nav>

  <div class="flex-grow-1 d-flex flex-column mobile-content-wrap">
    <!-- MOBILE TOPBAR: hidden at >=768px, matches the sidebar's own d-md-none header -->
    <div class="mobile-topbar d-md-none d-flex align-items-center gap-2 p-3">
      <button type="button" class="btn mobile-hamburger-btn" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="<?= __('common_menu') ?>">
        <i class="bi bi-list fs-4"></i>
      </button>
      <img src="<?= BASE_URL ?>/assets/logo-192.png" alt="" width="22" height="22">
      <span class="fs-6 fw-bold"><?= __('app_brand_name') ?></span>
    </div>

    <!-- MAIN CONTENT -->
    <main class="flex-grow-1 p-4">
