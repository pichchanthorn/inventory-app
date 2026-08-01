<?php
require_once __DIR__ . '/../config/base_url.php';
// $activePage should be set by the including page, e.g. $activePage = 'category';
$activePage = $activePage ?? '';
function navClass($page, $active) {
    return $page === $active ? 'nav-link active fw-semibold' : 'nav-link text-secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>
  if (localStorage.getItem('theme') === 'light') {
    document.documentElement.classList.add('theme-light-pending');
  }
</script>
<meta charset="UTF-8">
<title>Inventory</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css?v=<?= ASSET_VER ?>">
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
  <!-- SIDEBAR -->
  <nav class="sidebar p-3" style="width:230px;">
    <div class="d-flex align-items-center gap-2 mb-4">
      <span class="barcode text-primary"><i style="height:60%"></i><i style="height:100%"></i><i style="height:40%"></i><i style="height:80%"></i></span>
      <span class="fs-5 fw-bold">Inventory</span>
    </div>

    <div class="sidebar-section"><?= __('nav_overview') ?></div>
    <a class="<?= navClass('dashboard', $activePage) ?>" href="<?= BASE_URL ?>/dashboard.php"><i class="bi bi-speedometer2 me-2"></i><?= __('nav_dashboard') ?></a>

    <div class="sidebar-section"><?= __('nav_catalog') ?></div>
    <a class="<?= navClass('product', $activePage) ?>" href="<?= BASE_URL ?>/product/index.php"><i class="bi bi-box me-2"></i><?= __('nav_products') ?></a>
    <a class="<?= navClass('category', $activePage) ?>" href="<?= BASE_URL ?>/category/index.php"><i class="bi bi-tags me-2"></i><?= __('nav_categories') ?></a>
    <a class="<?= navClass('unit', $activePage) ?>" href="<?= BASE_URL ?>/unit/index.php"><i class="bi bi-rulers me-2"></i><?= __('nav_units') ?></a>
    <a class="<?= navClass('supplier', $activePage) ?>" href="<?= BASE_URL ?>/supplier/index.php"><i class="bi bi-truck me-2"></i><?= __('nav_suppliers') ?></a>

    <div class="sidebar-section"><?= __('nav_operation') ?></div>
    <a class="<?= navClass('stock-in', $activePage) ?>" href="<?= BASE_URL ?>/stock-in/index.php"><i class="bi bi-download me-2"></i><?= __('nav_stock_in') ?></a>
    <a class="<?= navClass('stock-out', $activePage) ?>" href="<?= BASE_URL ?>/stock-out/index.php"><i class="bi bi-upload me-2"></i><?= __('nav_stock_out') ?></a>
    <a class="<?= navClass('stock-adjustment', $activePage) ?>" href="<?= BASE_URL ?>/stock-adjustment/index.php"><i class="bi bi-arrow-repeat me-2"></i><?= __('nav_stock_adjustments') ?></a>

    <div class="sidebar-section"><?= __('nav_reports') ?></div>
    <a class="<?= navClass('stock-report', $activePage) ?>" href="<?= BASE_URL ?>/stock-report/index.php"><i class="bi bi-bar-chart me-2"></i><?= __('nav_stock_reports') ?></a>

    <?php if (function_exists('isAdmin') && isAdmin()): ?>
    <div class="sidebar-section"><?= __('nav_administration') ?></div>
    <a class="<?= navClass('user', $activePage) ?>" href="<?= BASE_URL ?>/user/index.php"><i class="bi bi-people me-2"></i><?= __('nav_users') ?></a>
    <?php endif; ?>

    <div class="sidebar-section"><?= __('nav_account') ?></div>
    <a class="<?= navClass('profile', $activePage) ?>" href="<?= BASE_URL ?>/profile.php"><i class="bi bi-person-circle me-2"></i><?= __('nav_profile') ?></a>
    <button type="button" class="theme-toggle-btn" onclick="toggleTheme()">
      <i class="bi bi-circle-half"></i> <span id="themeToggleLabel">Dark</span>
    </button>
    <a href="?lang=<?= $_SESSION['lang'] === 'km' ? 'en' : 'km' ?>" class="theme-toggle-btn text-decoration-none d-block text-center">
      <?= $_SESSION['lang'] === 'km' ? 'EN' : 'ខ្មែរ' ?>
    </a>
    <a class="nav-link text-secondary" href="<?= BASE_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-left me-2"></i><?= __('nav_sign_out') ?></a>
  </nav>

  <!-- MAIN CONTENT -->
  <main class="flex-grow-1 p-4">
