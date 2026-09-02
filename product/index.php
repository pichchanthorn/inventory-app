<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/sortable.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'product';
$error = '';

$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$suppliers  = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
$units      = $pdo->query('SELECT * FROM units ORDER BY name')->fetchAll();

// Used both server-side (resolvePriceField, below) to convert a KHR entry
// to the USD value actually stored, and exposed to JS as a page-global
// constant purely for the live "≈" preview text - the client never
// computes the value that gets submitted.
$khrRateRow = $pdo->query('SELECT usd_to_khr_rate FROM app_settings WHERE id = 1')->fetchColumn();
$khrRate = $khrRateRow !== false ? (float) $khrRateRow : null;

function nullableInt($v) { return $v === '' ? null : (int) $v; }

// Supplementary, non-authoritative provenance for the audit snapshot:
// present only when a price field was actually entered in KHR, so an
// all-USD edit's snapshot has the same shape as Categories/Units/
// Suppliers' - cost_price/sale_price themselves always stay canonical
// USD regardless (see includes/currency.php's resolvePriceField(),
// which this never overrides or duplicates the logic of).
function priceEntryAuditFields(array $post, string $field): array {
    $currency = ($post[$field . '_currency'] ?? 'USD') === 'KHR' ? 'KHR' : 'USD';
    if ($currency !== 'KHR') {
        return [];
    }
    return [
        $field . '_entry_currency' => 'KHR',
        $field . '_entry_khr_raw' => (float) ($post[$field] ?? 0),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'create') {
    if (!canWrite()) {
        $error = __('common_err_forbidden');
    } else {
        $name = trim($_POST['name']);
        $sku  = trim($_POST['sku']);
        if ($name === '' || $sku === '') {
            $error = __('product_err_required');
        } else {
            $stmt = $pdo->prepare('SELECT id FROM products WHERE sku = ?');
            $stmt->execute([$sku]);
            if ($stmt->fetch()) {
                $error = __('product_err_sku_exists');
            } else {
                $actorId = (int) $_SESSION['user_id'];
                try {
                    $costPrice = resolvePriceField($_POST, 'cost_price', $khrRate);
                    $salePrice = resolvePriceField($_POST, 'sale_price', $khrRate);

                    $barcode = trim($_POST['barcode']);
                    $categoryId = nullableInt($_POST['category_id']);
                    $supplierId = nullableInt($_POST['supplier_id']);
                    $unitId = nullableInt($_POST['unit_id']);
                    $packageSize = trim($_POST['package_size']);
                    $note = trim($_POST['note']);
                    $minStock = (int) $_POST['min_stock'];

                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare('INSERT INTO products
                        (name, sku, barcode, category_id, supplier_id, unit_id, package_size, note, cost_price, sale_price, min_stock, current_stock, created_by, updated_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?)');
                    $stmt->execute([
                        $name, $sku, $barcode, $categoryId, $supplierId, $unitId,
                        $packageSize, $note, $costPrice, $salePrice, $minStock,
                        $actorId, $actorId,
                    ]);
                    $newId = (int) $pdo->lastInsertId();

                    // Read the row back rather than hand-building $after from
                    // POST values - guarantees every actual column (including
                    // ones this form doesn't edit, e.g. active_ingredient/
                    // expiry_date) is represented accurately, not silently
                    // missing. current_stock excluded - it's hardcoded 0 above
                    // and owned entirely by Stock In/Out/Adjustment's own
                    // append-only history, not by Product CRUD. See §9.4.
                    $afterStmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
                    $afterStmt->execute([$newId]);
                    $after = $afterStmt->fetch();
                    unset($after['current_stock']);
                    $after += priceEntryAuditFields($_POST, 'cost_price');
                    $after += priceEntryAuditFields($_POST, 'sale_price');

                    logAudit($pdo, $actorId, 'create', 'product', $newId, null, $after);
                    $pdo->commit();
                    header('Location: ' . BASE_URL . '/product/index.php');
                    exit;
                } catch (PriceConversionException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = $e->getMessage();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Product create failed: ' . $e->getMessage());
                    $error = __('common_err_transaction_failed');
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'update') {
    if (!canWrite()) {
        $error = __('common_err_forbidden');
    } else {
        $id = (int) $_POST['id'];
        $name = trim($_POST['name']);
        $sku  = trim($_POST['sku']);
        if ($name === '' || $sku === '') {
            $error = __('product_err_required');
        } else {
            $actorId = (int) $_SESSION['user_id'];

            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $stmt->execute([$id]);
            $before = $stmt->fetch();
            if ($before) {
                // Product CRUD's audit trail never carries stock state - a
                // concurrent Stock In/Out changing current_stock must never
                // show up as part of what THIS edit changed. See §9.4.
                unset($before['current_stock']);
            }

            try {
                $costPrice = resolvePriceField($_POST, 'cost_price', $khrRate);
                $salePrice = resolvePriceField($_POST, 'sale_price', $khrRate);

                $barcode = trim($_POST['barcode']);
                $categoryId = nullableInt($_POST['category_id']);
                $supplierId = nullableInt($_POST['supplier_id']);
                $unitId = nullableInt($_POST['unit_id']);
                $packageSize = trim($_POST['package_size']);
                $note = trim($_POST['note']);
                $minStock = (int) $_POST['min_stock'];

                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE products SET
                    name=?, sku=?, barcode=?, category_id=?, supplier_id=?, unit_id=?, package_size=?, note=?, cost_price=?, sale_price=?, min_stock=?, updated_by=?
                    WHERE id=?');
                $stmt->execute([
                    $name, $sku, $barcode, $categoryId, $supplierId, $unitId,
                    $packageSize, $note, $costPrice, $salePrice, $minStock,
                    $actorId, $id,
                ]);

                // Same read-back approach as create - see the comment there.
                $afterStmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
                $afterStmt->execute([$id]);
                $after = $afterStmt->fetch();
                unset($after['current_stock']);
                $after += priceEntryAuditFields($_POST, 'cost_price');
                $after += priceEntryAuditFields($_POST, 'sale_price');

                logAudit($pdo, $actorId, 'update', 'product', $id, $before ?: null, $after);
                $pdo->commit();
                header('Location: ' . BASE_URL . '/product/index.php');
                exit;
            } catch (PriceConversionException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $e->getMessage();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Product update failed: ' . $e->getMessage());
                $error = __('common_err_transaction_failed');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isAdmin()) {
    $id = (int) $_POST['id'];
    $actorId = (int) $_SESSION['user_id'];

    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $before = $stmt->fetch();
    if ($before) {
        unset($before['current_stock']);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
        if ($before) {
            logAudit($pdo, $actorId, 'delete', 'product', $id, $before, null);
        }
        $pdo->commit();
        header('Location: ' . BASE_URL . '/product/index.php');
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // MySQL/MariaDB error 1451: "Cannot delete or update a parent row:
        // a foreign key constraint fails" - stock_transaction_items.product_id
        // has no ON DELETE clause (RESTRICT by default), so any product with
        // stock history (Stock In/Out/Adjustment/Sale - all four write to
        // that table) hits this. Give a precise message instead of the
        // generic fallback - same distinction StockConflictException gets
        // its own catch for elsewhere in this app.
        if (($e->errorInfo[1] ?? null) === 1451) {
            $error = __('product_err_delete_has_history');
        } else {
            error_log('Product delete failed: ' . $e->getMessage());
            $error = __('common_err_transaction_failed');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Product delete failed: ' . $e->getMessage());
        $error = __('common_err_transaction_failed');
    }
}

$search = trim($_GET['q'] ?? '');
$lowStockOnly = ($_GET['filter'] ?? '') === 'low_stock';
$orderBy = sortOrderBy(['name' => 'p.name', 'stock' => 'p.current_stock', 'price' => 'p.sale_price'], 'p.id DESC');

// Mobile-only "Sort by" <select> fallback: the table's <thead> (and with
// it, sortHeader()'s click-to-sort column links) is hidden below the
// card-layout breakpoint - see .table-cards-mobile in assets/style.css -
// so this reuses the same ?sort=&dir= URL scheme against the same 3
// sortable columns, just as a <select> instead of header links.
function mobileSortUrl(string $column): string {
    $params = $_GET;
    $params['sort'] = $column;
    $params['dir'] = 'asc';
    return '?' . http_build_query($params);
}
$sql = 'SELECT p.*, c.name AS category_name, s.name AS supplier_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE (p.name LIKE ? OR p.sku LIKE ?)' . ($lowStockOnly ? ' AND p.current_stock <= p.min_stock' : '') . "
        ORDER BY $orderBy";
$stmt = $pdo->prepare($sql);
$stmt->execute(["%$search%", "%$search%"]);
$products = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($error) ?>, 'error'));</script><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><?= __('product_title') ?></h4>
  <?php if (canWrite()): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
    <i class="bi bi-plus-lg"></i> <?= __('common_add') ?>
  </button>
  <?php endif; ?>
</div>

<?php if ($lowStockOnly): ?>
<div class="alert alert-warning py-2 d-flex justify-content-between align-items-center">
  <span><i class="bi bi-funnel"></i> <?= __('product_filter_low_stock_active') ?></span>
  <a href="<?= BASE_URL ?>/product/index.php" class="btn btn-sm btn-outline-secondary"><?= __('product_filter_clear') ?></a>
</div>
<?php endif; ?>

<form class="mb-3 d-flex gap-2 flex-wrap" method="get">
  <?php if ($lowStockOnly): ?><input type="hidden" name="filter" value="low_stock"><?php endif; ?>
  <input type="text" name="q" id="searchInput" class="form-control" style="max-width:300px"
         placeholder="<?= __('product_search_placeholder') ?>" value="<?= htmlspecialchars($search) ?>">
  <select class="form-select form-select-sm d-md-none" style="max-width:160px" onchange="if (this.value) location.href = this.value">
    <option value="" <?= empty($_GET['sort']) ? 'selected' : '' ?> disabled><?= __('common_sort_by') ?></option>
    <option value="<?= htmlspecialchars(mobileSortUrl('name')) ?>" <?= ($_GET['sort'] ?? '') === 'name' ? 'selected' : '' ?>><?= __('common_product') ?></option>
    <option value="<?= htmlspecialchars(mobileSortUrl('price')) ?>" <?= ($_GET['sort'] ?? '') === 'price' ? 'selected' : '' ?>><?= __('product_col_price') ?></option>
    <option value="<?= htmlspecialchars(mobileSortUrl('stock')) ?>" <?= ($_GET['sort'] ?? '') === 'stock' ? 'selected' : '' ?>><?= __('product_col_stock') ?></option>
  </select>
</form>

<div class="card" id="resultsArea">
  <table class="table mb-0 align-middle table-cards-mobile">
    <thead class="table-light">
      <tr><th>#</th><th><?= sortHeader('name', __('common_product')) ?></th><th><?= __('common_category') ?></th><th><?= __('common_supplier') ?></th><th><?= __('product_col_cost') ?></th><th><?= sortHeader('price', __('product_col_price')) ?></th><th><?= __('product_col_margin') ?></th><th><?= sortHeader('stock', __('product_col_stock')) ?></th><th class="text-end"><?= __('common_actions') ?></th></tr>
    </thead>
    <tbody>
      <?php if (!$products): ?>
        <tr><td colspan="9" class="text-center text-secondary py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i><?= __('product_empty') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($products as $i => $p):
        $margin = $p['sale_price'] > 0 ? round((($p['sale_price'] - $p['cost_price']) / $p['sale_price']) * 100) : 0;
        $low = $p['current_stock'] <= $p['min_stock'];
      ?>
      <tr class="<?= $low ? 'row-low-stock' : '' ?>">
        <td class="row-number"><?= $i + 1 ?></td>
        <td class="row-title">
          <?php if ($low): ?><span class="low-stock-badge"><i class="bi bi-exclamation-triangle-fill"></i> <?= __('product_low_stock_badge') ?></span><?php endif; ?>
          <div class="fw-semibold"><?= htmlspecialchars($p['name']) ?></div>
          <span class="slug-pill"><?= htmlspecialchars($p['sku']) ?></span>
          <?php if (!empty($p['package_size'])): ?><span class="text-secondary small ms-1"><?= htmlspecialchars($p['package_size']) ?></span><?php endif; ?>
        </td>
        <td data-label="<?= htmlspecialchars(__('common_category')) ?>"><?= $p['category_name'] ? htmlspecialchars($p['category_name']) : '<span class="text-secondary">—</span>' ?></td>
        <td data-label="<?= htmlspecialchars(__('common_supplier')) ?>"><?= $p['supplier_name'] ? htmlspecialchars($p['supplier_name']) : '<span class="text-secondary">—</span>' ?></td>
        <td class="mono" data-label="<?= htmlspecialchars(__('product_col_cost')) ?>">$<?= number_format($p['cost_price'], 2) ?></td>
        <td class="mono" data-label="<?= htmlspecialchars(__('product_col_price')) ?>">$<?= number_format($p['sale_price'], 2) ?></td>
        <td data-label="<?= htmlspecialchars(__('product_col_margin')) ?>" style="color:<?= $margin >= 30 ? 'var(--good)' : ($margin >= 15 ? 'var(--warn)' : 'var(--danger)') ?>;"><?= $margin ?>%</td>
        <td data-label="<?= htmlspecialchars(__('product_col_stock')) ?>"><span class="badge-stock <?= $low ? 'badge-low' : 'badge-normal' ?>"><?= $p['current_stock'] ?> <?= __('common_pcs') ?></span></td>
        <td class="text-end row-actions">
          <?php if (canWrite()): ?>
          <button class="btn btn-sm btn-outline-primary"
                  data-bs-toggle="modal" data-bs-target="#editModal<?= $p['id'] ?>">
            <i class="bi bi-pencil"></i>
          </button>
          <?php endif; ?>
          <?php if (isAdmin()): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('<?= __('product_delete_confirm') ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php foreach ($products as $i => $p): ?>
<div class="modal fade" id="editModal<?= $p['id'] ?>" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= $p['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title"><?= __('product_edit_title') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __('common_close') ?>"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label"><?= __('common_name') ?></label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($p['name']) ?>" required></div>
          <div class="row">
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('product_sku') ?></label>
              <input type="text" name="sku" class="form-control" value="<?= htmlspecialchars($p['sku']) ?>" required></div>
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('product_barcode') ?></label>
              <input type="text" name="barcode" class="form-control" value="<?= htmlspecialchars($p['barcode']) ?>"></div>
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('product_package_size') ?></label>
              <input type="text" name="package_size" class="form-control" value="<?= htmlspecialchars($p['package_size'] ?? '') ?>" placeholder="<?= __('product_package_size_placeholder') ?>"></div>
          </div>
          <div class="row">
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('common_category') ?></label>
              <select name="category_id" class="form-select">
                <option value=""><?= __('common_none_option') ?></option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $c['id']==$p['category_id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('common_supplier') ?></label>
              <select name="supplier_id" class="form-select">
                <option value=""><?= __('common_none_option') ?></option>
                <?php foreach ($suppliers as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $s['id']==$p['supplier_id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('common_unit') ?></label>
              <select name="unit_id" class="form-select">
                <option value=""><?= __('common_none_option') ?></option>
                <?php foreach ($units as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $u['id']==$p['unit_id']?'selected':'' ?>><?= htmlspecialchars($u['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
          </div>
          <div class="row">
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('product_cost_price') ?></label>
              <div class="input-group price-input-group">
                <button type="button" class="btn btn-outline-secondary currency-toggle-btn" onclick="toggleCurrency(this)"<?= $khrRate ? '' : ' disabled title="' . htmlspecialchars(__('currency_err_no_rate_configured')) . '"' ?>>$</button>
                <input type="number" step="0.01" name="cost_price" class="form-control price-amount-input" value="<?= $p['cost_price'] ?>" oninput="updatePricePreview(this)">
                <input type="hidden" name="cost_price_currency" class="price-currency-input" value="USD">
              </div>
              <div class="text-secondary small price-preview"></div></div>
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('product_sale_price') ?></label>
              <div class="input-group price-input-group">
                <button type="button" class="btn btn-outline-secondary currency-toggle-btn" onclick="toggleCurrency(this)"<?= $khrRate ? '' : ' disabled title="' . htmlspecialchars(__('currency_err_no_rate_configured')) . '"' ?>>$</button>
                <input type="number" step="0.01" name="sale_price" class="form-control price-amount-input" value="<?= $p['sale_price'] ?>" oninput="updatePricePreview(this)">
                <input type="hidden" name="sale_price_currency" class="price-currency-input" value="USD">
              </div>
              <div class="text-secondary small price-preview"></div></div>
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('product_min_stock') ?></label>
              <input type="number" name="min_stock" class="form-control" value="<?= $p['min_stock'] ?>"></div>
          </div>
          <div class="mb-3"><label class="form-label"><?= __('common_note') ?></label>
            <textarea name="note" class="form-control"><?= htmlspecialchars($p['note']) ?></textarea></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('common_cancel') ?></button>
          <button class="btn btn-primary"><?= __('common_save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<div class="modal fade" id="createModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title"><?= __('product_create_title') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __('common_close') ?>"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label"><?= __('common_name') ?></label>
            <input type="text" name="name" class="form-control" required></div>
          <div class="row">
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('product_sku') ?></label>
              <input type="text" name="sku" class="form-control" required></div>
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('product_barcode') ?></label>
              <input type="text" name="barcode" class="form-control"></div>
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('product_package_size') ?></label>
              <input type="text" name="package_size" class="form-control" placeholder="<?= __('product_package_size_placeholder') ?>"></div>
          </div>
          <div class="row">
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('common_category') ?></label>
              <select name="category_id" class="form-select">
                <option value=""><?= __('common_none_option') ?></option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('common_supplier') ?></label>
              <select name="supplier_id" class="form-select">
                <option value=""><?= __('common_none_option') ?></option>
                <?php foreach ($suppliers as $s): ?>
                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('common_unit') ?></label>
              <select name="unit_id" class="form-select">
                <option value=""><?= __('common_none_option') ?></option>
                <?php foreach ($units as $u): ?>
                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
          </div>
          <div class="row">
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('product_cost_price') ?></label>
              <div class="input-group price-input-group">
                <button type="button" class="btn btn-outline-secondary currency-toggle-btn" onclick="toggleCurrency(this)"<?= $khrRate ? '' : ' disabled title="' . htmlspecialchars(__('currency_err_no_rate_configured')) . '"' ?>>$</button>
                <input type="number" step="0.01" name="cost_price" class="form-control price-amount-input" value="0" oninput="updatePricePreview(this)">
                <input type="hidden" name="cost_price_currency" class="price-currency-input" value="USD">
              </div>
              <div class="text-secondary small price-preview"></div></div>
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('product_sale_price') ?></label>
              <div class="input-group price-input-group">
                <button type="button" class="btn btn-outline-secondary currency-toggle-btn" onclick="toggleCurrency(this)"<?= $khrRate ? '' : ' disabled title="' . htmlspecialchars(__('currency_err_no_rate_configured')) . '"' ?>>$</button>
                <input type="number" step="0.01" name="sale_price" class="form-control price-amount-input" value="0" oninput="updatePricePreview(this)">
                <input type="hidden" name="sale_price_currency" class="price-currency-input" value="USD">
              </div>
              <div class="text-secondary small price-preview"></div></div>
            <div class="col-12 col-md-4 mb-3"><label class="form-label"><?= __('product_min_stock') ?></label>
              <input type="number" name="min_stock" class="form-control" value="0"></div>
          </div>
          <div class="mb-3"><label class="form-label"><?= __('common_note') ?></label>
            <textarea name="note" class="form-control"></textarea></div>
          <p class="text-secondary small mb-0"><?= __('product_stock_hint') ?></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('common_cancel') ?></button>
          <button class="btn btn-primary"><?= __('common_save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => initLiveSearch('searchInput', 'resultsArea'));

// Live-preview-only KHR<->USD conversion for the Cost/Sale Price currency
// toggle. This never decides what gets submitted - the server always
// resolves the authoritative USD value from the raw amount + currency
// flag (includes/currency.php's resolvePriceField()), independently of
// whatever this preview shows or whether JS even ran.
const EXCHANGE_RATE = <?= json_encode($khrRate) ?>;

function toggleCurrency(btn) {
  if (btn.disabled) return;
  const group = btn.closest('.price-input-group');
  const input = group.querySelector('.price-amount-input');
  const currencyInput = group.querySelector('.price-currency-input');
  const current = parseFloat(input.value) || 0;
  if (currencyInput.value === 'USD') {
    currencyInput.value = 'KHR';
    btn.textContent = '៛';
    input.value = Math.round(current * EXCHANGE_RATE);
    input.step = '1';
  } else {
    currencyInput.value = 'USD';
    btn.textContent = '$';
    input.value = (current / EXCHANGE_RATE).toFixed(2);
    input.step = '0.01';
  }
  updatePricePreview(input);
}

function updatePricePreview(input) {
  const group = input.closest('.price-input-group');
  const currencyInput = group.querySelector('.price-currency-input');
  const preview = group.parentElement.querySelector('.price-preview');
  if (!preview) return;
  const amount = parseFloat(input.value) || 0;
  if (!EXCHANGE_RATE || amount <= 0) { preview.textContent = ''; return; }
  preview.textContent = currencyInput.value === 'USD'
    ? `≈ ៛${Math.round(amount * EXCHANGE_RATE).toLocaleString()}`
    : `≈ $${(amount / EXCHANGE_RATE).toFixed(2)}`;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
