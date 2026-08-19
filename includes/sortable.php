<?php
// ================================================
// Click-to-sort table headers (Products, Categories, Units, Suppliers,
// Stock Report log). Column keys are matched against a per-page whitelist
// so arbitrary column names never reach SQL — the actual SQL fragment
// stays in the whitelist array, never in $_GET.
// ================================================

// Builds an ORDER BY fragment from the current ?sort=&dir= query params,
// falling back to $defaultOrderBy when no sort is active (or the column
// isn't in $sqlMap).
function sortOrderBy(array $sqlMap, string $defaultOrderBy) {
    $col = $_GET['sort'] ?? '';
    $dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    return isset($sqlMap[$col]) ? $sqlMap[$col] . ' ' . $dir : $defaultOrderBy;
}

// Renders a clickable column header: label + link that toggles asc/desc,
// preserving every other query param already on the page (search, filter),
// plus a small arrow icon showing the active sort column and direction.
function sortHeader(string $column, string $label) {
    $activeSort = $_GET['sort'] ?? '';
    $activeDir  = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    $nextDir = ($activeSort === $column && $activeDir === 'asc') ? 'desc' : 'asc';

    $params = $_GET;
    $params['sort'] = $column;
    $params['dir'] = $nextDir;
    $url = '?' . http_build_query($params);

    if ($activeSort === $column) {
        $icon = '<i class="bi bi-arrow-' . ($activeDir === 'asc' ? 'up' : 'down') . ' sort-icon sort-icon-active"></i>';
    } else {
        $icon = '<i class="bi bi-arrow-down-up sort-icon"></i>';
    }

    return '<a href="' . htmlspecialchars($url) . '" class="sort-link">' . htmlspecialchars($label) . ' ' . $icon . '</a>';
}
