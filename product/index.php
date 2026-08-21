<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/sortable.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'product';
$error = '';

$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$suppliers  = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
$units      = $pdo->query('SELECT * FROM units ORDER BY name')->fetchAll();

function nullableInt($v) { return $v === '' ? null : (int) $v; }

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
                $stmt = $pdo->prepare('INSERT INTO products
                    (name, sku, barcode, category_id, supplier_id, unit_id, note, cost_price, sale_price, min_stock, current_stock)
                    VALUES (?,?,?,?,?,?,?,?,?,?,0)');
                $stmt->execute([
                    $name, $sku, trim($_POST['barcode']),
                    nullableInt($_POST['category_id']), nullableInt($_POST['supplier_id']), nullableInt($_POST['unit_id']),
                    trim($_POST['note']), (float) $_POST['cost_price'], (float) $_POST['sale_price'], (int) $_POST['min_stock'],
                ]);
                header('Location: ' . BASE_URL . '/product/index.php');
                exit;
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
            $stmt = $pdo->prepare('UPDATE products SET
                name=?, sku=?, barcode=?, category_id=?, supplier_id=?, unit_id=?, note=?, cost_price=?, sale_price=?, min_stock=?
                WHERE id=?');
            $stmt->execute([
                $name, $sku, trim($_POST['barcode']),
                nullableInt($_POST['category_id']), nullableInt($_POST['supplier_id']), nullableInt($_POST['unit_id']),
                trim($_POST['note']), (float) $_POST['cost_price'], (float) $_POST['sale_price'], (int) $_POST['min_stock'],
                $id,
            ]);
            header('Location: ' . BASE_URL . '/product/index.php');
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isAdmin()) {
    $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([(int) $_POST['id']]);
    header('Location: ' . BASE_URL . '/product/index.php');
    exit;
}

$search = trim($_GET['q'] ?? '');
$lowStockOnly = ($_GET['filter'] ?? '') === 'low_stock';
$orderBy = sortOrderBy(['name' => 'p.name', 'stock' => 'p.current_stock', 'price' => 'p.sale_price'], 'p.id DESC');
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

<form class="mb-3" method="get">
  <?php if ($lowStockOnly): ?><input type="hidden" name="filter" value="low_stock"><?php endif; ?>
  <input type="text" name="q" id="searchInput" class="form-control" style="max-width:300px"
         placeholder="<?= __('product_search_placeholder') ?>" value="<?= htmlspecialchars($search) ?>">
</form>

<div class="card" id="resultsArea">
  <table class="table mb-0 align-middle">
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
        <td><?= $i + 1 ?></td>
        <td>
          <div class="fw-semibold"><?= htmlspecialchars($p['name']) ?></div>
          <span class="slug-pill"><?= htmlspecialchars($p['sku']) ?></span>
        </td>
        <td><?= $p['category_name'] ? htmlspecialchars($p['category_name']) : '<span class="text-secondary">—</span>' ?></td>
        <td><?= $p['supplier_name'] ? htmlspecialchars($p['supplier_name']) : '<span class="text-secondary">—</span>' ?></td>
        <td class="mono">$<?= number_format($p['cost_price'], 2) ?></td>
        <td class="mono">$<?= number_format($p['sale_price'], 2) ?></td>
        <td style="color:<?= $margin >= 30 ? 'var(--good)' : ($margin >= 15 ? 'var(--warn)' : 'var(--danger)') ?>;"><?= $margin ?>%</td>
        <td><span class="badge-stock <?= $low ? 'badge-low' : 'badge-normal' ?>"><?= $p['current_stock'] ?> <?= __('common_pcs') ?></span></td>
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

      <div class="modal fade" id="editModal<?= $p['id'] ?>" tabindex="-1">
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
                  <div class="col-6 mb-3"><label class="form-label"><?= __('product_sku') ?></label>
                    <input type="text" name="sku" class="form-control" value="<?= htmlspecialchars($p['sku']) ?>" required></div>
                  <div class="col-6 mb-3"><label class="form-label"><?= __('product_barcode') ?></label>
                    <input type="text" name="barcode" class="form-control" value="<?= htmlspecialchars($p['barcode']) ?>"></div>
                </div>
                <div class="row">
                  <div class="col-4 mb-3"><label class="form-label"><?= __('common_category') ?></label>
                    <select name="category_id" class="form-select">
                      <option value=""><?= __('common_none_option') ?></option>
                      <?php foreach ($categories as $c): ?>
                      <option value="<?= $c['id'] ?>" <?= $c['id']==$p['category_id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                      <?php endforeach; ?>
                    </select></div>
                  <div class="col-4 mb-3"><label class="form-label"><?= __('common_supplier') ?></label>
                    <select name="supplier_id" class="form-select">
                      <option value=""><?= __('common_none_option') ?></option>
                      <?php foreach ($suppliers as $s): ?>
                      <option value="<?= $s['id'] ?>" <?= $s['id']==$p['supplier_id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
                      <?php endforeach; ?>
                    </select></div>
                  <div class="col-4 mb-3"><label class="form-label"><?= __('common_unit') ?></label>
                    <select name="unit_id" class="form-select">
                      <option value=""><?= __('common_none_option') ?></option>
                      <?php foreach ($units as $u): ?>
                      <option value="<?= $u['id'] ?>" <?= $u['id']==$p['unit_id']?'selected':'' ?>><?= htmlspecialchars($u['name']) ?></option>
                      <?php endforeach; ?>
                    </select></div>
                </div>
                <div class="row">
                  <div class="col-4 mb-3"><label class="form-label"><?= __('product_cost_price') ?></label>
                    <input type="number" step="0.01" name="cost_price" class="form-control" value="<?= $p['cost_price'] ?>"></div>
                  <div class="col-4 mb-3"><label class="form-label"><?= __('product_sale_price') ?></label>
                    <input type="number" step="0.01" name="sale_price" class="form-control" value="<?= $p['sale_price'] ?>"></div>
                  <div class="col-4 mb-3"><label class="form-label"><?= __('product_min_stock') ?></label>
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
    </tbody>
  </table>
</div>

<div class="modal fade" id="createModal" tabindex="-1">
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
            <div class="col-6 mb-3"><label class="form-label"><?= __('product_sku') ?></label>
              <input type="text" name="sku" class="form-control" required></div>
            <div class="col-6 mb-3"><label class="form-label"><?= __('product_barcode') ?></label>
              <input type="text" name="barcode" class="form-control"></div>
          </div>
          <div class="row">
            <div class="col-4 mb-3"><label class="form-label"><?= __('common_category') ?></label>
              <select name="category_id" class="form-select">
                <option value=""><?= __('common_none_option') ?></option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-4 mb-3"><label class="form-label"><?= __('common_supplier') ?></label>
              <select name="supplier_id" class="form-select">
                <option value=""><?= __('common_none_option') ?></option>
                <?php foreach ($suppliers as $s): ?>
                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-4 mb-3"><label class="form-label"><?= __('common_unit') ?></label>
              <select name="unit_id" class="form-select">
                <option value=""><?= __('common_none_option') ?></option>
                <?php foreach ($units as $u): ?>
                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
          </div>
          <div class="row">
            <div class="col-4 mb-3"><label class="form-label"><?= __('product_cost_price') ?></label>
              <input type="number" step="0.01" name="cost_price" class="form-control" value="0"></div>
            <div class="col-4 mb-3"><label class="form-label"><?= __('product_sale_price') ?></label>
              <input type="number" step="0.01" name="sale_price" class="form-control" value="0"></div>
            <div class="col-4 mb-3"><label class="form-label"><?= __('product_min_stock') ?></label>
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

<script>document.addEventListener('DOMContentLoaded', () => initLiveSearch('searchInput', 'resultsArea'));</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
