<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'product';
$error = '';

$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$suppliers  = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
$units      = $pdo->query('SELECT * FROM units ORDER BY name')->fetchAll();

function nullableInt($v) { return $v === '' ? null : (int) $v; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'create') {
    $name = trim($_POST['name']);
    $sku  = trim($_POST['sku']);
    if ($name === '' || $sku === '') {
        $error = 'Name and SKU are required.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE sku = ?');
        $stmt->execute([$sku]);
        if ($stmt->fetch()) {
            $error = 'This SKU already exists.';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'update') {
    $id = (int) $_POST['id'];
    $stmt = $pdo->prepare('UPDATE products SET
        name=?, sku=?, barcode=?, category_id=?, supplier_id=?, unit_id=?, note=?, cost_price=?, sale_price=?, min_stock=?
        WHERE id=?');
    $stmt->execute([
        trim($_POST['name']), trim($_POST['sku']), trim($_POST['barcode']),
        nullableInt($_POST['category_id']), nullableInt($_POST['supplier_id']), nullableInt($_POST['unit_id']),
        trim($_POST['note']), (float) $_POST['cost_price'], (float) $_POST['sale_price'], (int) $_POST['min_stock'],
        $id,
    ]);
    header('Location: ' . BASE_URL . '/product/index.php');
    exit;
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    header('Location: ' . BASE_URL . '/product/index.php');
    exit;
}

$search = trim($_GET['q'] ?? '');
$sql = 'SELECT p.*, c.name AS category_name, s.name AS supplier_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE p.name LIKE ? OR p.sku LIKE ?
        ORDER BY p.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute(["%$search%", "%$search%"]);
$products = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Products</h4>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
    <i class="bi bi-plus-lg"></i> Add
  </button>
</div>

<form class="mb-3" method="get">
  <input type="text" name="q" class="form-control" style="max-width:300px"
         placeholder="Search products or SKU..." value="<?= htmlspecialchars($search) ?>">
</form>

<div class="card">
  <table class="table mb-0 align-middle">
    <thead class="table-light">
      <tr><th>#</th><th>Product</th><th>Category</th><th>Supplier</th><th>Cost</th><th>Price</th><th>Margin</th><th>Stock</th><th class="text-end">Actions</th></tr>
    </thead>
    <tbody>
      <?php if (!$products): ?>
        <tr><td colspan="9" class="text-center text-secondary py-4">No products yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($products as $i => $p):
        $margin = $p['sale_price'] > 0 ? round((($p['sale_price'] - $p['cost_price']) / $p['sale_price']) * 100) : 0;
        $low = $p['current_stock'] <= $p['min_stock'];
      ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td>
          <div class="fw-semibold"><?= htmlspecialchars($p['name']) ?></div>
          <span class="slug-pill"><?= htmlspecialchars($p['sku']) ?></span>
        </td>
        <td><?= $p['category_name'] ? htmlspecialchars($p['category_name']) : '<span class="text-secondary">—</span>' ?></td>
        <td><?= $p['supplier_name'] ? htmlspecialchars($p['supplier_name']) : '<span class="text-secondary">—</span>' ?></td>
        <td>$<?= number_format($p['cost_price'], 2) ?></td>
        <td>$<?= number_format($p['sale_price'], 2) ?></td>
        <td style="color:<?= $margin >= 30 ? 'var(--good)' : ($margin >= 15 ? 'var(--warn)' : 'var(--danger)') ?>;"><?= $margin ?>%</td>
        <td><span class="badge-stock <?= $low ? 'badge-low' : 'badge-normal' ?>"><?= $p['current_stock'] ?> pcs</span></td>
        <td class="text-end">
          <button class="btn btn-sm btn-outline-primary"
                  data-bs-toggle="modal" data-bs-target="#editModal<?= $p['id'] ?>">
            <i class="bi bi-pencil"></i>
          </button>
          <a class="btn btn-sm btn-outline-danger"
             href="?delete=<?= $p['id'] ?>"
             onclick="return confirm('Delete this product?')">
            <i class="bi bi-trash"></i>
          </a>
        </td>
      </tr>

      <div class="modal fade" id="editModal<?= $p['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content">
            <form method="post">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= $p['id'] ?>">
              <div class="modal-header">
                <h5 class="modal-title">Edit product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <div class="mb-3"><label class="form-label">Name</label>
                  <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($p['name']) ?>" required></div>
                <div class="row">
                  <div class="col-6 mb-3"><label class="form-label">SKU</label>
                    <input type="text" name="sku" class="form-control" value="<?= htmlspecialchars($p['sku']) ?>" required></div>
                  <div class="col-6 mb-3"><label class="form-label">Barcode</label>
                    <input type="text" name="barcode" class="form-control" value="<?= htmlspecialchars($p['barcode']) ?>"></div>
                </div>
                <div class="row">
                  <div class="col-4 mb-3"><label class="form-label">Category</label>
                    <select name="category_id" class="form-select">
                      <option value="">— None —</option>
                      <?php foreach ($categories as $c): ?>
                      <option value="<?= $c['id'] ?>" <?= $c['id']==$p['category_id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                      <?php endforeach; ?>
                    </select></div>
                  <div class="col-4 mb-3"><label class="form-label">Supplier</label>
                    <select name="supplier_id" class="form-select">
                      <option value="">— None —</option>
                      <?php foreach ($suppliers as $s): ?>
                      <option value="<?= $s['id'] ?>" <?= $s['id']==$p['supplier_id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
                      <?php endforeach; ?>
                    </select></div>
                  <div class="col-4 mb-3"><label class="form-label">Unit</label>
                    <select name="unit_id" class="form-select">
                      <option value="">— None —</option>
                      <?php foreach ($units as $u): ?>
                      <option value="<?= $u['id'] ?>" <?= $u['id']==$p['unit_id']?'selected':'' ?>><?= htmlspecialchars($u['name']) ?></option>
                      <?php endforeach; ?>
                    </select></div>
                </div>
                <div class="row">
                  <div class="col-4 mb-3"><label class="form-label">Cost price</label>
                    <input type="number" step="0.01" name="cost_price" class="form-control" value="<?= $p['cost_price'] ?>"></div>
                  <div class="col-4 mb-3"><label class="form-label">Sale price</label>
                    <input type="number" step="0.01" name="sale_price" class="form-control" value="<?= $p['sale_price'] ?>"></div>
                  <div class="col-4 mb-3"><label class="form-label">Min stock</label>
                    <input type="number" name="min_stock" class="form-control" value="<?= $p['min_stock'] ?>"></div>
                </div>
                <div class="mb-3"><label class="form-label">Note</label>
                  <textarea name="note" class="form-control"><?= htmlspecialchars($p['note']) ?></textarea></div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Save</button>
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
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title">Create product</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
          <div class="mb-3"><label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" required></div>
          <div class="row">
            <div class="col-6 mb-3"><label class="form-label">SKU</label>
              <input type="text" name="sku" class="form-control" required></div>
            <div class="col-6 mb-3"><label class="form-label">Barcode</label>
              <input type="text" name="barcode" class="form-control"></div>
          </div>
          <div class="row">
            <div class="col-4 mb-3"><label class="form-label">Category</label>
              <select name="category_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-4 mb-3"><label class="form-label">Supplier</label>
              <select name="supplier_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($suppliers as $s): ?>
                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-4 mb-3"><label class="form-label">Unit</label>
              <select name="unit_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($units as $u): ?>
                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
          </div>
          <div class="row">
            <div class="col-4 mb-3"><label class="form-label">Cost price</label>
              <input type="number" step="0.01" name="cost_price" class="form-control" value="0"></div>
            <div class="col-4 mb-3"><label class="form-label">Sale price</label>
              <input type="number" step="0.01" name="sale_price" class="form-control" value="0"></div>
            <div class="col-4 mb-3"><label class="form-label">Min stock</label>
              <input type="number" name="min_stock" class="form-control" value="0"></div>
          </div>
          <div class="mb-3"><label class="form-label">Note</label>
            <textarea name="note" class="form-control"></textarea></div>
          <p class="text-secondary small mb-0">Stock starts at 0 — add quantity via Stock In.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
