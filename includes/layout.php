<?php
function page_header(string $title, string $active = ''): void {
    $nav = [
        'dashboard.php' => 'Dashboard',
        'accounts.php'  => 'Accounts',
        'partners.php'  => 'Partnerships',
        'catalog.php'   => 'Vehicles & services',
        'activity.php'  => 'Activity',
        'reports.php'   => 'Reports',
        'areas.php'     => 'Areas',
        'logs.php'      => 'Admin log',
    ];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . e($title) . ' - Admin</title>'
       . '<link rel="stylesheet" href="../assets/style.css"></head><body>'
       . '<header><strong>Admin</strong><nav>';
    foreach ($nav as $file => $label) {
        echo '<a href="' . $file . '"' . ($active === $file ? ' class="on"' : '') . '>' . $label . '</a>';
    }
    echo '</nav><a href="profile.php">Profile</a><a href="../logout.php">Logout</a></header><main><h1>' . e($title) . '</h1>';
    if (!empty($_SESSION['flash'])) {
        echo '<p class="flash">' . e($_SESSION['flash']) . '</p>';
        unset($_SESSION['flash']);
    }
}

function page_footer(): void {
    echo '</main></body></html>';
}

// Simple escaped table. $rows is a list of associative arrays; $headers maps column key => label.
// $links maps a column key to a function(row): ?string that returns an href for that cell.
function render_table(array $headers, array $rows, array $links = []): void {
    if (!$rows) {
        echo '<p class="muted">Nothing to show yet.</p>';
        return;
    }
    echo '<div class="scroll"><table><thead><tr>';
    foreach ($headers as $label) {
        echo '<th>' . e($label) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($headers as $key => $_) {
            $cell = e($row[$key] ?? '');
            if (isset($links[$key]) && ($href = $links[$key]($row))) {
                $cell = '<a href="' . e($href) . '">' . $cell . '</a>';
            }
            echo '<td>' . $cell . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

// Label/value list for detail pages. A value may be text, or ['html' => '...'] for trusted markup.
function render_kv(array $pairs): void {
    echo '<dl class="kv">';
    foreach ($pairs as $label => $value) {
        $html = is_array($value) ? ($value['html'] ?? '') : e(($value === null || $value === '') ? '-' : $value);
        echo '<dt>' . e($label) . '</dt><dd>' . $html . '</dd>';
    }
    echo '</dl>';
}

function account_link(int $id, string $name): array {
    return ['html' => '<a href="account.php?id=' . $id . '">' . e($name) . '</a>'];
}

function money($n): string {
    return number_format((float)$n, 2);
}

// ---- Pagination ----------------------------------------------------------------------------
// $total = number of rows matching the filters. Reads ?page= (or another key) from the query string.
function paginate(int $total, int $perPage = 50, string $key = 'page'): array {
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min(max(1, (int)($_GET[$key] ?? 1)), $pages);
    return ['page' => $page, 'per' => $perPage, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'total' => $total, 'key' => $key];
}

// Prev / numbered / Next links that keep every other filter in the query string.
function render_pager(array $p): void {
    if ($p['pages'] <= 1) {
        return;
    }
    $q = $_GET;
    $link = function (int $n, string $label, bool $on = false) use ($q, $p): string {
        $q[$p['key']] = $n;
        return '<a href="?' . e(http_build_query($q)) . '"' . ($on ? ' class="on"' : '') . '>' . $label . '</a>';
    };
    echo '<div class="tabs" style="margin-top:1rem">';
    if ($p['page'] > 1) echo $link($p['page'] - 1, '&larr; Prev');
    for ($n = max(1, $p['page'] - 2); $n <= min($p['pages'], $p['page'] + 2); $n++) {
        echo $link($n, (string)$n, $n === $p['page']);
    }
    if ($p['page'] < $p['pages']) echo $link($p['page'] + 1, 'Next &rarr;');
    echo '<span class="muted" style="align-self:center">Page ' . $p['page'] . ' of ' . $p['pages'] . ' (' . number_format($p['total']) . ' rows)</span></div>';
}
