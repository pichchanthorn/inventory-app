<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'unit';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'create') {
    $name = trim($_POST['name']);
    $note = trim($_POST['note']);
    if ($name === '') {
        $error = __('common_err_name_required');
    } else {
        $stmt = $pdo->prepare('INSERT INTO units (name, note) VALUES (?, ?)');
        $stmt->execute([$name, $note]);
        header('Location: ' . BASE_URL . '/unit/index.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'update') {
    $id   = (int) $_POST['id'];
    $name = trim($_POST['name']);
    $note = trim($_POST['note']);
    $stmt = $pdo->prepare('UPDATE units SET name = ?, note = ? WHERE id = ?');
    $stmt->execute([$name, $note, $id]);
    header('Location: ' . BASE_URL . '/unit/index.php');
    exit;
}

if (isset($_GET['delete']) && isAdmin()) {
    $stmt = $pdo->prepare('DELETE FROM units WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    header('Location: ' . BASE_URL . '/unit/index.php');
    exit;
}

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare('SELECT * FROM units WHERE name LIKE ? ORDER BY id DESC');
    $stmt->execute(["%$search%"]);
} else {
    $stmt = $pdo->query('SELECT * FROM units ORDER BY id DESC');
}
$units = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><?= __('unit_title') ?></h4>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
    <i class="bi bi-plus-lg"></i> <?= __('common_add') ?>
  </button>
</div>

<form class="mb-3" method="get">
  <input type="text" name="q" class="form-control" style="max-width:300px"
         placeholder="<?= __('common_search_placeholder') ?>" value="<?= htmlspecialchars($search) ?>">
</form>

<div class="card">
  <table class="table mb-0 align-middle">
    <thead class="table-light">
      <tr><th>#</th><th><?= __('common_name') ?></th><th><?= __('common_note') ?></th><th class="text-end"><?= __('common_actions') ?></th></tr>
    </thead>
    <tbody>
      <?php if (!$units): ?>
        <tr><td colspan="4" class="text-center text-secondary py-4"><?= __('unit_empty') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($units as $i => $u): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= htmlspecialchars($u['name']) ?></td>
        <td><?= htmlspecialchars($u['note']) ?></td>
        <td class="text-end">
          <button class="btn btn-sm btn-outline-primary"
                  data-bs-toggle="modal" data-bs-target="#editModal<?= $u['id'] ?>">
            <i class="bi bi-pencil"></i>
          </button>
          <?php if (isAdmin()): ?>
          <a class="btn btn-sm btn-outline-danger"
             href="?delete=<?= $u['id'] ?>"
             onclick="return confirm('<?= __('unit_delete_confirm') ?>')">
            <i class="bi bi-trash"></i>
          </a>
          <?php endif; ?>
        </td>
      </tr>

      <div class="modal fade" id="editModal<?= $u['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content">
            <form method="post">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= $u['id'] ?>">
              <div class="modal-header">
                <h5 class="modal-title"><?= __('unit_edit_title') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <div class="mb-3">
                  <label class="form-label"><?= __('common_name') ?></label>
                  <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($u['name']) ?>" required>
                </div>
                <div class="mb-3">
                  <label class="form-label"><?= __('common_note') ?></label>
                  <textarea name="note" class="form-control"><?= htmlspecialchars($u['note']) ?></textarea>
                </div>
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
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title"><?= __('unit_create_title') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
          <div class="mb-3">
            <label class="form-label"><?= __('common_name') ?></label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= __('common_note') ?></label>
            <textarea name="note" class="form-control"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('common_cancel') ?></button>
          <button class="btn btn-primary"><?= __('common_save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
