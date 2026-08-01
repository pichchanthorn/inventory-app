<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'category';
$error = '';

// ---------- CREATE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'create') {
    $name = trim($_POST['name']);
    $slug = trim($_POST['slug']);
    $note = trim($_POST['note']);

    if ($name === '' || $slug === '') {
        $error = 'Name and Slug are required.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO categories (name, slug, note) VALUES (?, ?, ?)');
        $stmt->execute([$name, $slug, $note]);
        header('Location: ' . BASE_URL . '/category/index.php');
        exit;
    }
}

// ---------- UPDATE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'update') {
    $id   = (int) $_POST['id'];
    $name = trim($_POST['name']);
    $slug = trim($_POST['slug']);
    $note = trim($_POST['note']);

    $stmt = $pdo->prepare('UPDATE categories SET name = ?, slug = ?, note = ? WHERE id = ?');
    $stmt->execute([$name, $slug, $note, $id]);
    header('Location: ' . BASE_URL . '/category/index.php');
    exit;
}

// ---------- DELETE (Admin only) ----------
if (isset($_GET['delete']) && isAdmin()) {
    $id = (int) $_GET['delete'];
    $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    header('Location: ' . BASE_URL . '/category/index.php');
    exit;
}

// ---------- SEARCH + LIST ----------
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE name LIKE ? ORDER BY id DESC');
    $stmt->execute(["%$search%"]);
} else {
    $stmt = $pdo->query('SELECT * FROM categories ORDER BY id DESC');
}
$categories = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Categories</h4>
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
      <tr><th>#</th><th>Name</th><th>Slug</th><th>Note</th><th class="text-end">Actions</th></tr>
    </thead>
    <tbody>
      <?php if (!$categories): ?>
        <tr><td colspan="5" class="text-center text-secondary py-4">No categories yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($categories as $i => $cat): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= htmlspecialchars($cat['name']) ?></td>
        <td><span class="slug-pill"><?= htmlspecialchars($cat['slug']) ?></span></td>
        <td><?= htmlspecialchars($cat['note']) ?></td>
        <td class="text-end">
          <button class="btn btn-sm btn-outline-primary"
                  data-bs-toggle="modal" data-bs-target="#editModal<?= $cat['id'] ?>">
            <i class="bi bi-pencil"></i>
          </button>
          <?php if (isAdmin()): ?>
          <a class="btn btn-sm btn-outline-danger"
             href="?delete=<?= $cat['id'] ?>"
             onclick="return confirm('Delete this category?')">
            <i class="bi bi-trash"></i>
          </a>
          <?php endif; ?>
        </td>
      </tr>

      <!-- Edit modal for this row -->
      <div class="modal fade" id="editModal<?= $cat['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content">
            <form method="post">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= $cat['id'] ?>">
              <div class="modal-header">
                <h5 class="modal-title">Edit category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <div class="mb-3">
                  <label class="form-label">Name</label>
                  <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($cat['name']) ?>" required>
                </div>
                <div class="mb-3">
                  <label class="form-label">Slug</label>
                  <input type="text" name="slug" class="form-control" value="<?= htmlspecialchars($cat['slug']) ?>" required>
                </div>
                <div class="mb-3">
                  <label class="form-label">Note</label>
                  <textarea name="note" class="form-control"><?= htmlspecialchars($cat['note']) ?></textarea>
                </div>
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

<!-- Create modal -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title">Create category</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
          <div class="mb-3">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Slug</label>
            <input type="text" name="slug" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Note</label>
            <textarea name="note" class="form-control"></textarea>
          </div>
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
