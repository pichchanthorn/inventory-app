<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/sortable.php';
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

// ---------- V2-B2: filters ----------
// Every filter below is validated/whitelisted before it ever reaches SQL -
// $entityTypeFilter and $actionFilter especially, since those are the two
// that would otherwise let a raw $_GET value sit inside the query string.
// $search deliberately never touches before_snapshot/after_snapshot (JSON,
// unindexed, and not what "search" means to an admin reading this log) -
// it only matches the actor's name, the entity_type string, and (when the
// term looks like an id) an exact entity_id match.
$search = trim($_GET['q'] ?? '');

$actorFilter = null;
if (isset($_GET['actor']) && ctype_digit((string) $_GET['actor'])) {
    $actorFilter = (int) $_GET['actor'];
}

$validEntityTypes = $pdo->query('SELECT DISTINCT entity_type FROM audit_log ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);
$entityTypeFilter = $_GET['entity_type'] ?? '';
if (!in_array($entityTypeFilter, $validEntityTypes, true)) {
    $entityTypeFilter = '';
}

$validActions = ['create', 'update', 'delete'];
$actionFilter = $_GET['action'] ?? '';
if (!in_array($actionFilter, $validActions, true)) {
    $actionFilter = '';
}

// Same Y-m-d validation convention as purchase-order/create.php's own
// order_date/expected_date checks - an invalid or malformed date is
// treated as "no filter" here (this is a GET filter, not a form
// submission), same forgiving-reset-to-blank spirit as this page's own
// $entityTypeFilter/$actionFilter whitelisting above.
$dateFrom = $_GET['date_from'] ?? '';
$dateFromObj = DateTime::createFromFormat('Y-m-d', $dateFrom);
if (!$dateFromObj || $dateFromObj->format('Y-m-d') !== $dateFrom) {
    $dateFrom = '';
}
$dateTo = $_GET['date_to'] ?? '';
$dateToObj = DateTime::createFromFormat('Y-m-d', $dateTo);
if (!$dateToObj || $dateToObj->format('Y-m-d') !== $dateTo) {
    $dateTo = '';
}

$actors = $pdo->query('SELECT id, name FROM users ORDER BY name')->fetchAll();

// One shared $where/$params pair, used verbatim by BOTH the COUNT(*) and
// the paginated SELECT below - so the two can never drift apart and
// report mismatched totals.
$where = [];
$params = [];
if ($search !== '') {
    if (ctype_digit($search)) {
        $where[] = '(u.name LIKE ? OR a.entity_type LIKE ? OR a.entity_id = ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = (int) $search;
    } else {
        $where[] = '(u.name LIKE ? OR a.entity_type LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
}
if ($actorFilter !== null) {
    $where[] = 'a.user_id = ?';
    $params[] = $actorFilter;
}
if ($entityTypeFilter !== '') {
    $where[] = 'a.entity_type = ?';
    $params[] = $entityTypeFilter;
}
if ($actionFilter !== '') {
    $where[] = 'a.action = ?';
    $params[] = $actionFilter;
}
if ($dateFrom !== '') {
    $where[] = 'a.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $dateTo;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id = a.user_id $whereSql");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();

// ---------- Pagination: fixed page size, LIMIT/OFFSET ----------
// Replaces the old flat "LIMIT 200" growth-safety cap - real pages now,
// instead of an invisible cutoff past row 200.
$pageSize = 50;
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
$page = max(1, (int) ($_GET['page'] ?? 1));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $pageSize;

// Default ordering MUST stay a.id DESC - id is the only column immune to
// same-second ties from concurrent inserts (created_at is a 1-second-
// resolution TIMESTAMP). sortOrderBy()'s single-column-plus-trailing-
// direction model can't express a two-column composite tiebreak, so the
// "time" sort is composed directly here instead of forcing it through
// that helper - sortHeader() below (unmodified, as-is) is still reused
// for the actual clickable link/URL-building.
$sortCol = $_GET['sort'] ?? '';
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$orderBy = ($sortCol === 'time') ? "a.created_at $sortDir, a.id $sortDir" : 'a.id DESC';

$stmt = $pdo->prepare("SELECT a.*, u.name AS actor_name
                        FROM audit_log a
                        LEFT JOIN users u ON u.id = a.user_id
                        $whereSql
                        ORDER BY $orderBy
                        LIMIT $pageSize OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$actionBadgeClass = ['create' => 'badge-normal', 'update' => 'badge-accent', 'delete' => 'badge-low'];
$actionLabels = ['create' => __('audit_action_create'), 'update' => __('audit_action_update'), 'delete' => __('audit_action_delete')];
// entity_type values are the lowercase singular strings each module
// passes to logAudit() - reusing existing common_*/entity-label keys
// where one already exists, adding just one new key (customer_debt) for
// the one entity type with no suitable existing label.
$entityTypeLabels = [
    'category' => __('common_category'),
    'unit' => __('common_unit'),
    'supplier' => __('common_supplier'),
    'product' => __('common_product'),
    'user' => __('common_user'),
    'purchase_order' => __('po_entity_label'),
    'customer' => __('pos_customer_label'),
    'customer_debt' => __('audit_entity_customer_debt'),
    'product_batch' => __('stockadj_batch_label'),
    'backup' => __('settings_backup_title'),
];

function auditPageUrl(int $targetPage): string {
    $params = $_GET;
    $params['page'] = $targetPage;
    return '?' . http_build_query($params);
}

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4"><?= __('audit_title') ?></h4>

<form class="mb-3 d-flex gap-2 flex-wrap align-items-center list-toolbar" method="get">
  <input type="text" name="q" class="form-control search-input"
         placeholder="<?= __('common_search_placeholder') ?>" value="<?= htmlspecialchars($search) ?>">
  <select name="actor" class="form-select" style="max-width:200px;" onchange="this.form.submit()">
    <option value=""><?= __('audit_filter_all_actors') ?></option>
    <?php foreach ($actors as $a): ?>
    <option value="<?= $a['id'] ?>" <?= $actorFilter === (int) $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="entity_type" class="form-select" style="max-width:200px;" onchange="this.form.submit()">
    <option value=""><?= __('audit_filter_all_entity_types') ?></option>
    <?php foreach ($validEntityTypes as $et): ?>
    <option value="<?= htmlspecialchars($et) ?>" <?= $entityTypeFilter === $et ? 'selected' : '' ?>><?= htmlspecialchars($entityTypeLabels[$et] ?? $et) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="action" class="form-select" style="max-width:160px;" onchange="this.form.submit()">
    <option value=""><?= __('audit_filter_all_actions') ?></option>
    <?php foreach ($validActions as $act): ?>
    <option value="<?= $act ?>" <?= $actionFilter === $act ? 'selected' : '' ?>><?= htmlspecialchars($actionLabels[$act]) ?></option>
    <?php endforeach; ?>
  </select>
  <span class="text-secondary small"><?= __('audit_filter_from_label') ?></span>
  <input type="date" name="date_from" class="form-control" style="max-width:160px;" value="<?= htmlspecialchars($dateFrom) ?>" onchange="this.form.submit()">
  <span class="text-secondary small"><?= __('audit_filter_to_label') ?></span>
  <input type="date" name="date_to" class="form-control" style="max-width:160px;" value="<?= htmlspecialchars($dateTo) ?>" onchange="this.form.submit()">
  <?php if ($search !== '' || $actorFilter !== null || $entityTypeFilter !== '' || $actionFilter !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
  <a href="?" class="btn btn-sm btn-outline-secondary"><?= __('product_filter_clear') ?></a>
  <?php endif; ?>
</form>

<div class="card">
  <table class="table mb-0 align-middle table-cards-mobile">
    <thead class="table-light">
      <tr>
        <th><?= sortHeader('time', __('audit_col_time')) ?></th>
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
        <td class="mono text-secondary small" data-label="<?= htmlspecialchars(__('audit_col_time')) ?>"><?= htmlspecialchars($row['created_at']) ?></td>
        <td data-label="<?= htmlspecialchars(__('audit_col_actor')) ?>"><?= htmlspecialchars($row['actor_name'] ?? '—') ?></td>
        <td data-label="<?= htmlspecialchars(__('audit_col_action')) ?>"><span class="badge-stock <?= $actionBadgeClass[$row['action']] ?? 'badge-normal' ?>"><?= htmlspecialchars($actionLabels[$row['action']] ?? $row['action']) ?></span></td>
        <td data-label="<?= htmlspecialchars(__('audit_col_entity_type')) ?>"><?= htmlspecialchars($entityTypeLabels[$row['entity_type']] ?? $row['entity_type']) ?></td>
        <td data-label="<?= htmlspecialchars(__('audit_col_entity')) ?>"><?= htmlspecialchars($entityName) ?></td>
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

<?php if ($totalRows > 0): ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
  <div class="text-secondary small">
    <?= htmlspecialchars(sprintf(__('audit_pagination_summary'), $offset + 1, min($offset + $pageSize, $totalRows), $totalRows)) ?>
  </div>
  <nav aria-label="<?= htmlspecialchars(__('audit_title')) ?>">
    <ul class="pagination pagination-sm mb-0">
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= htmlspecialchars(auditPageUrl(max(1, $page - 1))) ?>"><?= __('audit_pagination_previous') ?></a>
      </li>
      <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= htmlspecialchars(auditPageUrl(min($totalPages, $page + 1))) ?>"><?= __('audit_pagination_next') ?></a>
      </li>
    </ul>
  </nav>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
