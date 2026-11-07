<?php
if (!defined('ADMIN_BRAND')) {
    define('ADMIN_BRAND', 'OSAS');
    define('ADMIN_TAGLINE', 'Online Sakay at Serbisyo');
}

// Small stroke icons used by the sidebar and the dashboard.
function icon(string $name): string {
    static $p = [
        'home'     => '<path d="M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2 21c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M17 14.5c3 0 5 1.8 5 4.5"/>',
        'car'      => '<path d="M3 13l2-6h14l2 6v5H3z"/><circle cx="7.5" cy="18" r="1.3"/><circle cx="16.5" cy="18" r="1.3"/><path d="M3 13h18"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'chart'    => '<path d="M5 21V10M12 21V4M19 21v-7"/>',
        'pin'      => '<path d="M12 21s7-6.2 7-11a7 7 0 0 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'log'      => '<path d="M6 3h12v18H6z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'bell'     => '<path d="M6 9a6 6 0 0 1 12 0c0 6 2 7 2 7H4s2-1 2-7z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'wallet'   => '<path d="M3 7h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 7l12-3v3"/><circle cx="16.5" cy="13.5" r="1.2"/>',
        'briefcase'=> '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M3 13h18"/>',
        'userplus' => '<circle cx="10" cy="8" r="4"/><path d="M2 21c0-4 4-6 8-6s6 1.2 7 3"/><path d="M19 8v6M16 11h6"/>',
        'wrench'   => '<path d="M14.5 6.5a4 4 0 0 0 4.9 4.9L21 13l-3 3-1.6-1.6a4 4 0 0 0-1.1.7L8 21l-3-3 5.9-6.3a4 4 0 0 0 .7-1.1L9.9 9.1l3-3z"/>',
        'chev'     => '<path d="M6 9l6 6 6-6"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

// Things the admin should act on: shown in the bell on every admin page.
// Each item is [text, link, count].
function attention_items(): array {
    static $items = null;
    if ($items !== null) {
        return $items;
    }
    $items = [];
    try {
        $pdo = db();
        $b = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn();
        $r = (int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE status = 'pending'")->fetchColumn();
        $n = (int)$pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT a.province, a.city_municipality FROM areas a
                LEFT JOIN (SELECT DISTINCT ma.province, ma.city_municipality
                           FROM users u JOIN areas ma ON ma.id = u.area_id
                           WHERE u.role = 'manager' AND u.is_active = 1) m
                       ON m.province = a.province AND m.city_municipality = a.city_municipality
                WHERE m.province IS NULL GROUP BY a.province, a.city_municipality) t"
        )->fetchColumn();
        try {
            $rc = (int)$pdo->query("SELECT COUNT(*) FROM provider_recommendations WHERE status = 'pending'")->fetchColumn();
            if ($rc) $items[] = [$rc . ($rc === 1 ? ' provider recommendation' : ' provider recommendations') . ' to review', 'recommendations.php', $rc];
        } catch (Throwable $e) {
            // the recommendations table is added by migrations/001_provider_recommendations.sql
        }
        if ($b) $items[] = [$b . ($b === 1 ? ' booking' : ' bookings') . ' awaiting approval', 'activity.php?tab=bookings&status=pending', $b];
        if ($r) $items[] = [$r . ($r === 1 ? ' service request' : ' service requests') . ' awaiting approval', 'activity.php?tab=requests&status=pending', $r];
        if ($n) $items[] = [$n . ($n === 1 ? ' city has' : ' cities have') . ' no manager', 'areas.php', $n];
    } catch (Throwable $e) {
        // the bell is a convenience; never break a page because of it
    }
    return $items;
}

// $opts: 'welcome' => true shows "Welcome back, name!" beside the bell, 'subtitle' => text under the title,
//        'actions' => trusted HTML shown on the right of the title.
function page_header(string $title, string $active = '', array $opts = []): void {
    $nav = [
        'dashboard.php' => ['Dashboard', 'home'],
        'accounts.php'  => ['Accounts', 'user'],
        'partners.php'  => ['Partnerships', 'users'],
        'catalog.php'   => ['Vehicles & Services', 'car'],
        'activity.php'  => ['Activity', 'calendar'],
        'reports.php'   => ['Reports', 'chart'],
        'areas.php'     => ['Areas', 'pin'],
        'logs.php'      => ['Admin Log', 'log'],
    ];
    $nav   = $opts['nav'] ?? $nav;
    $label = (string)($opts['label'] ?? 'Admin');
    $name  = (string)($_SESSION['user']['name'] ?? $label);
    $first = function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1)) : strtoupper(substr($name, 0, 1));
    $items = $opts['attention'] ?? attention_items();
    $badge = array_sum(array_column($items, 2));
    ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> - <?= e(ADMIN_BRAND) ?> <?= e($label) ?></title>
<link rel="stylesheet" href="../assets/style.css"></head>
<body>
<div class="app">
  <aside class="side">
    <a class="brand" href="dashboard.php" aria-label="<?= e(ADMIN_BRAND) ?> dashboard">
      <svg viewBox="0 0 48 48" width="42" height="42" aria-hidden="true"><path d="M24 3c-8 0-14 6-14 14 0 10 14 26 14 26s14-16 14-26c0-8-6-14-14-14z" fill="#f5c542"/><circle cx="24" cy="17" r="5.5" fill="#0e5a37"/><path d="M6 45c11-7 25-7 36 0" stroke="#7cc68a" stroke-width="3" fill="none" stroke-linecap="round"/></svg>
      <span><strong><?= e(ADMIN_BRAND) ?></strong><small><?= e(ADMIN_TAGLINE) ?></small></span>
    </a>
    <nav aria-label="<?= e($label) ?>">
      <?php foreach ($nav as $file => [$label, $ic]): ?>
        <a href="<?= $file ?>"<?= $active === $file ? ' class="on" aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <svg class="road" viewBox="0 0 200 120" aria-hidden="true"><path d="M-10 125C40 90 110 100 210 40" /><path d="M-10 105C45 75 115 80 210 25" /></svg>
  </aside>

  <div class="content">
    <div class="bar">
      <?php if (!empty($opts['welcome'])): ?><span class="welcome">Welcome back, <strong><?= e($name) ?></strong>!</span><?php endif; ?>
      <details class="menu">
        <summary class="bell" aria-label="Needs attention"><?= icon('bell') ?><?php if ($badge): ?><b><?= $badge > 99 ? '99+' : (int)$badge ?></b><?php endif; ?></summary>
        <div class="pop">
          <p class="pop-h">Needs attention</p>
          <?php if (!$items): ?>
            <p class="muted pop-empty">All clear. Nothing is waiting.</p>
          <?php else: foreach ($items as [$text, $href]): ?>
            <a href="<?= e($href) ?>"><?= e($text) ?></a>
          <?php endforeach; endif; ?>
        </div>
      </details>
      <details class="menu">
        <summary class="me"><span class="av"><?= e($first) ?></span><span class="who"><?= e($name) ?></span><?= icon('chev') ?></summary>
        <div class="pop">
          <a href="profile.php">My profile</a>
          <a href="../logout.php">Log out</a>
        </div>
      </details>
    </div>

    <main class="page">
      <div class="pagehead">
        <div>
          <h1><?= e($title) ?></h1>
          <?php if (!empty($opts['subtitle'])): ?><p class="sub"><?= e($opts['subtitle']) ?></p><?php endif; ?>
        </div>
        <?php if (!empty($opts['actions'])) echo $opts['actions']; ?>
      </div>
<?php
    if (!empty($_SESSION['flash'])) {
        echo '<p class="flash">' . e($_SESSION['flash']) . '</p>';
        unset($_SESSION['flash']);
    }
}

function page_footer(): void {
    ?>
    </main>
  </div>
</div>
<script>
// Dropdown menus: close when clicking elsewhere or pressing Escape; opening one closes the others.
(function () {
  var menus = document.querySelectorAll('details.menu');
  document.addEventListener('click', function (ev) {
    menus.forEach(function (m) { if (!m.contains(ev.target)) m.removeAttribute('open'); });
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') menus.forEach(function (m) { m.removeAttribute('open'); });
  });
  menus.forEach(function (m) {
    m.addEventListener('toggle', function () {
      if (m.open) menus.forEach(function (o) { if (o !== m) o.removeAttribute('open'); });
    });
  });
})();
</script>
</body></html>
<?php
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
