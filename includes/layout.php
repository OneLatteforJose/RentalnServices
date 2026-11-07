<?php
defined('ADMIN_BRAND') || define('ADMIN_BRAND', 'OSAS');
defined('ADMIN_BRAND_LONG') || define('ADMIN_BRAND_LONG', 'Online Sakay at Serbisyo');

// Inline line icons (24x24, drawn with currentColor) so the theme needs no icon font or image files.
function icon(string $name): string {
    static $paths = [
        'home'     => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'user'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user-plus'=> '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>',
        'car'      => '<path d="M3 13l2-5a2 2 0 0 1 1.9-1.3h10.2A2 2 0 0 1 19 8l2 5v4a1 1 0 0 1-1 1h-1v1.5h-2V18H7v1.5H5V18H4a1 1 0 0 1-1-1z"/><circle cx="7.5" cy="14.5" r="1"/><circle cx="16.5" cy="14.5" r="1"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'chart'    => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'pin'      => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'briefcase'=> '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'wrench'   => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/>',
        'bell'     => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'chevron'  => '<polyline points="6 9 12 15 18 9"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

// Waiting approvals for the bell badge: [bookings, requests]. Two indexed COUNTs; never breaks a page if the query fails.
function pending_counts(): array {
    try {
        $pdo = db();
        return [
            (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn(),
            (int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE status = 'pending'")->fetchColumn(),
        ];
    } catch (Throwable $ex) {
        return [0, 0];
    }
}

function page_header(string $title, string $active = ''): void {
    $nav = [
        'dashboard.php' => ['Dashboard',           'home'],
        'accounts.php'  => ['Accounts',            'user'],
        'partners.php'  => ['Partnerships',        'users'],
        'catalog.php'   => ['Vehicles & Services', 'car'],
        'activity.php'  => ['Activity',            'calendar'],
        'reports.php'   => ['Reports',             'chart'],
        'areas.php'     => ['Areas',               'pin'],
        'logs.php'      => ['Admin Log',           'file'],
    ];
    $name = trim((string)($_SESSION['user']['name'] ?? 'Admin')) ?: 'Admin';
    $initial = strtoupper(function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1));
    [$pb, $pr] = pending_counts();
    $bell = $pb + $pr;
    $bellHref = 'activity.php?tab=' . ($pb === 0 && $pr > 0 ? 'requests' : 'bookings') . '&status=pending';

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . e($title) . ' - ' . e(ADMIN_BRAND) . '</title>'
       . '<link rel="stylesheet" href="../assets/style.css"></head><body><div class="app">'
       . '<aside class="sidebar"><a class="brand" href="dashboard.php">'
       . '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16 2C10.5 2 6 6.4 6 11.8 6 19 16 30 16 30s10-11 10-18.2C26 6.4 21.5 2 16 2z" fill="#f5b81c"/><circle cx="16" cy="12" r="4.2" fill="#0f5c3b"/></svg>'
       . '<span><b>' . e(ADMIN_BRAND) . '</b><small>' . e(ADMIN_BRAND_LONG) . '</small></span></a><nav aria-label="Admin">';
    foreach ($nav as $file => [$label, $ic]) {
        echo '<a href="' . $file . '"' . ($active === $file ? ' class="on" aria-current="page"' : '') . '>' . icon($ic) . '<span>' . e($label) . '</span></a>';
    }
    echo '</nav></aside><div class="main"><header class="topbar">'
       . '<span class="welcome">Welcome back, <b>' . e($name) . '</b>!</span>'
       . '<a class="bell" href="' . $bellHref . '" aria-label="' . $bell . ' pending approvals">' . icon('bell')
       . ($bell > 0 ? '<span class="dot">' . ($bell > 99 ? '99+' : $bell) . '</span>' : '') . '</a>'
       . '<details class="user"><summary><span class="avatar">' . e($initial) . '</span><span class="uname">' . e($name) . '</span>' . icon('chevron') . '</summary>'
       . '<div class="menu"><a href="profile.php">Profile</a><a href="../logout.php">Log out</a></div></details>'
       . '</header><main class="content"><h1>' . e($title) . '</h1>';
    if (!empty($_SESSION['flash'])) {
        echo '<p class="flash">' . e($_SESSION['flash']) . '</p>';
        unset($_SESSION['flash']);
    }
}

function page_footer(): void {
    echo '</main></div></div></body></html>';
}

// A stat card with an icon circle (dashboard). $hot gives it the gold "needs attention" outline.
function stat_card(string $ic, $value, string $label, string $sub = '', bool $hot = false, string $href = ''): string {
    $inner = '<span class="ic">' . icon($ic) . '</span><span><span class="num">' . e($value) . '</span><br>'
           . '<span class="lbl">' . e($label) . '</span>' . ($sub !== '' ? '<br><span class="sub">' . e($sub) . '</span>' : '') . '</span>';
    $cls = 'stat' . ($hot ? ' hot' : '');
    return $href !== '' ? '<a class="' . $cls . '" href="' . e($href) . '">' . $inner . '</a>' : '<div class="' . $cls . '">' . $inner . '</div>';
}

function peso($n): string {
    $n = (float)$n;
    return "\u{20B1}" . number_format($n, fmod($n, 1.0) == 0.0 ? 0 : 2);
}

// "5m ago" from an age in seconds.
function time_ago(int $seconds): string {
    if ($seconds < 60)    return 'just now';
    if ($seconds < 3600)  return intdiv($seconds, 60) . 'm ago';
    if ($seconds < 86400) return intdiv($seconds, 3600) . 'h ago';
    if ($seconds < 86400 * 30) return intdiv($seconds, 86400) . 'd ago';
    return intdiv($seconds, 86400 * 30) . 'mo ago';
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
            if ($key === 'status' && $cell !== '') {
                $cell = '<span class="badge badge-' . preg_replace('/[^a-z]/', '', strtolower((string)$row[$key])) . '">' . $cell . '</span>';
            }
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
