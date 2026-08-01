<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'supplier';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'create') {
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $note = trim($_POST['note']);
    if ($name === '') {
        $error = 'Name is required.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO suppliers (name, phone, email, address, note) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$name, $phone, $email, $address, $note]);
        header('Location: ' . BASE_URL . '/supplier/index.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'update') {
    $id = (int) $_POST['id'];
    $stmt = $pdo->prepare('UPDATE suppliers SET name=?, phone=?, email=?, address=?, note=? WHERE id=?');
    $stmt->execute([trim($_POST['name']), trim($_POST['phone']), trim($_POST['email']), trim($_POST['address']), trim($_POST['note']), $id]);
    header('Location: ' . BASE_URL . '/supplier/index.php');
    exit;
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM suppliers WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    header('Location: ' . BASE_URL . '/supplier/index.php');
    exit;
}

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE name LIKE ? ORDER BY id DESC');
    $stmt->execute(["%$search%"]);
} else {
    $stmt = $pdo->query('SELECT * FROM suppliers ORDER BY id DESC');
}
$suppliers = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Suppliers</h4>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
    <i class="bi bi-plus-lg"></i> Add
  </button>
</div>

<form class="mb-3" method="get">
  <input type="text" name="q" class="form-control" style="max-width:300px"
         placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
</form>

<div class="card">
  <table class="table mb-0 align-middle">
    <thead class="table-light">
      <tr><th>#</th><th>Name</th><th>Phone</th><th>Email</th><th>Address</th><th>Note</th><th class="text-end">Actions</th></tr>
    </thead>
    <tbody>
      <?php if (!$suppliers): ?>
        <tr><td colspan="7" class="text-center text-secondary py-4">No suppliers yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($suppliers as $i => $s): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= htmlspecialchars($s['name']) ?></td>
        <td><?= htmlspecialchars($s['phone']) ?></td>
        <td><?= htmlspecialchars($s['email']) ?></td>
        <td><?= htmlspecialchars($s['address']) ?></td>
        <td><?= htmlspecialchars($s['note']) ?></td>
        <td class="text-end">
          <button class="btn btn-sm btn-outline-primary"
                  data-bs-toggle="modal" data-bs-target="#editModal<?= $s['id'] ?>">
            <i class="bi bi-pencil"></i>
          </button>
          <a class="btn btn-sm btn-outline-danger"
             href="?delete=<?= $s['id'] ?>"
             onclick="return confirm('Delete this supplier?')">
            <i class="bi bi-trash"></i>
          </a>
        </td>
      </tr>

      <div class="modal fade" id="editModal<?= $s['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content">
            <form method="post">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= $s['id'] ?>">
              <div class="modal-header">
                <h5 class="modal-title">Edit supplier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <div class="mb-3"><label class="form-label">Name</label>
                  <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($s['name']) ?>" required></div>
                <div class="mb-3"><label class="form-label">Phone</label>
                  <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($s['phone']) ?>"></div>
                <div class="mb-3"><label class="form-label">Email</label>
                  <input type="text" name="email" class="form-control" value="<?= htmlspecialchars($s['email']) ?>"></div>
                <div class="mb-3"><label class="form-label">Address</label>
                  <textarea name="address" class="form-control"><?= htmlspecialchars($s['address']) ?></textarea></div>
                <div class="mb-3"><label class="form-label">Note</label>
                  <textarea name="note" class="form-control"><?= htmlspecialchars($s['note']) ?></textarea></div>
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
          <h5 class="modal-title">Create supplier</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
          <div class="mb-3"><label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-control"></div>
          <div class="mb-3"><label class="form-label">Email</label>
            <input type="text" name="email" class="form-control"></div>
          <div class="mb-3"><label class="form-label">Address</label>
            <textarea name="address" class="form-control"></textarea></div>
          <div class="mb-3"><label class="form-label">Note</label>
            <textarea name="note" class="form-control"></textarea></div>
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
