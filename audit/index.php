<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';

// Admin-only, matching Users/Settings - same isAdmin() gate, redirecting
// non-Admins away rather than just hiding the nav link.
if (!isAdmin()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$activePage = 'audit';

// Read-only. No UPDATE/DELETE path exists against audit_log anywhere in
// this app, and none should ever be added - see includes/audit.php and
// database/schema.sql's audit_log comment for why.
//
// LIMIT 200: the minimum growth-safety measure for Phase 1 - no
// filtering/search/pagination yet (explicitly future-phase), just a cap
// so this stays a "scannable list" rather than an unbounded page as the
// table accumulates faster than stock_transactions does.
$rows = $pdo->query("SELECT a.*, u.name AS actor_name
                      FROM audit_log a
                      LEFT JOIN users u ON u.id = a.user_id
                      ORDER BY a.id DESC
                      LIMIT 200")->fetchAll();

$actionBadgeClass = ['create' => 'badge-normal', 'update' => 'badge-accent', 'delete' => 'badge-low'];
$actionLabels = ['create' => __('audit_action_create'), 'update' => __('audit_action_update'), 'delete' => __('audit_action_delete')];
// entity_type values are the lowercase singular strings each module
// passes to logAudit() ('category'/'unit'/'supplier' this batch) -
// reusing the existing common_* labels rather than inventing new ones.
$entityTypeLabels = ['category' => __('common_category'), 'unit' => __('common_unit'), 'supplier' => __('common_supplier')];

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4"><?= __('audit_title') ?></h4>

<div class="card">
  <table class="table mb-0 align-middle">
    <thead class="table-light">
      <tr>
        <th><?= __('audit_col_time') ?></th>
        <th><?= __('audit_col_actor') ?></th>
        <th><?= __('audit_col_action') ?></th>
        <th><?= __('audit_col_entity_type') ?></th>
        <th><?= __('audit_col_entity') ?></th>
        <th><?= __('audit_view_details') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-center text-secondary py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i><?= __('audit_empty') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row):
        $before = $row['before_snapshot'] !== null ? json_decode($row['before_snapshot'], true) : null;
        $after  = $row['after_snapshot']  !== null ? json_decode($row['after_snapshot'], true)  : null;
        $entityName = ($after['name'] ?? null) ?? ($before['name'] ?? '—');
      ?>
      <tr>
        <td class="mono text-secondary small"><?= htmlspecialchars($row['created_at']) ?></td>
        <td><?= htmlspecialchars($row['actor_name'] ?? '—') ?></td>
        <td><span class="badge-stock <?= $actionBadgeClass[$row['action']] ?? 'badge-normal' ?>"><?= htmlspecialchars($actionLabels[$row['action']] ?? $row['action']) ?></span></td>
        <td><?= htmlspecialchars($entityTypeLabels[$row['entity_type']] ?? $row['entity_type']) ?></td>
        <td><?= htmlspecialchars($entityName) ?></td>
        <td>
          <details>
            <summary class="text-primary" style="cursor:pointer;"><?= __('audit_view_details') ?></summary>
            <div class="mt-2 small">
              <?php if ($before !== null): ?>
                <div class="text-secondary fw-semibold"><?= __('audit_before_label') ?></div>
                <pre class="mono mb-2" style="white-space:pre-wrap;"><?= htmlspecialchars(json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
              <?php endif; ?>
              <?php if ($after !== null): ?>
                <div class="text-secondary fw-semibold"><?= __('audit_after_label') ?></div>
                <pre class="mono mb-0" style="white-space:pre-wrap;"><?= htmlspecialchars(json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
              <?php endif; ?>
            </div>
          </details>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
