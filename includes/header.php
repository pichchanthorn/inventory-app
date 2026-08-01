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
<meta charset="UTF-8">
<title>Inventory</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
<style>
  .sidebar { min-height:100vh; }
  .sidebar .nav-link { padding:.5rem 1rem; margin-bottom:2px; }
</style>
</head>
<body>
<div class="d-flex">
  <!-- SIDEBAR -->
  <nav class="sidebar p-3" style="width:230px;">
    <div class="d-flex align-items-center gap-2 mb-4">
      <span class="barcode text-primary"><i style="height:60%"></i><i style="height:100%"></i><i style="height:40%"></i><i style="height:80%"></i></span>
      <span class="fs-5 fw-bold">Inventory</span>
    </div>

    <div class="sidebar-section">Overview</div>
    <a class="<?= navClass('dashboard', $activePage) ?>" href="<?= BASE_URL ?>/dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>

    <div class="sidebar-section">Catalog</div>
    <a class="<?= navClass('product', $activePage) ?>" href="<?= BASE_URL ?>/product/index.php"><i class="bi bi-box me-2"></i>Products</a>
    <a class="<?= navClass('category', $activePage) ?>" href="<?= BASE_URL ?>/category/index.php"><i class="bi bi-tags me-2"></i>Categories</a>
    <a class="<?= navClass('unit', $activePage) ?>" href="<?= BASE_URL ?>/unit/index.php"><i class="bi bi-rulers me-2"></i>Units</a>
    <a class="<?= navClass('supplier', $activePage) ?>" href="<?= BASE_URL ?>/supplier/index.php"><i class="bi bi-truck me-2"></i>Suppliers</a>

    <div class="sidebar-section">Operation</div>
    <a class="<?= navClass('stock-in', $activePage) ?>" href="<?= BASE_URL ?>/stock-in/index.php"><i class="bi bi-download me-2"></i>Stock In</a>
    <a class="<?= navClass('stock-out', $activePage) ?>" href="<?= BASE_URL ?>/stock-out/index.php"><i class="bi bi-upload me-2"></i>Stock Out</a>
    <a class="<?= navClass('stock-adjustment', $activePage) ?>" href="<?= BASE_URL ?>/stock-adjustment/index.php"><i class="bi bi-arrow-repeat me-2"></i>Stock Adjustments</a>

    <div class="sidebar-section">Reports</div>
    <a class="<?= navClass('stock-report', $activePage) ?>" href="<?= BASE_URL ?>/stock-report/index.php"><i class="bi bi-bar-chart me-2"></i>Stock Reports</a>

    <div class="sidebar-section">Account</div>
    <a class="<?= navClass('profile', $activePage) ?>" href="<?= BASE_URL ?>/profile.php"><i class="bi bi-person-circle me-2"></i>Profile</a>
    <a class="nav-link text-secondary" href="<?= BASE_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-left me-2"></i>Sign out</a>
  </nav>

  <!-- MAIN CONTENT -->
  <main class="flex-grow-1 p-4">
