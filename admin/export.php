<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';
require_admin();

$type = $_GET['type'] ?? '';
$f = report_filters();
$pdo = db();
// Unbuffered: MySQL streams rows to PHP one at a time, so a 50,000-row export does not sit in memory.
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
$data = report_dataset($pdo, $type, $f);
if ($data === null) {
    http_response_code(400);
    exit('Unknown export type.');
}
[$headers, $st] = $data;

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $type . '_' . $f['from'] . '_to_' . $f['to'] . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 byte-order mark so Excel reads accents correctly
fputcsv($out, array_values($headers), ',', '"', '');
$n = 0;
while ($row = $st->fetch()) {
    $line = [];
    foreach ($headers as $key => $_) {
        $line[] = csv_safe($row[$key] ?? '');
    }
    fputcsv($out, $line, ',', '"', '');
    $n++;
}
fclose($out);
$st->closeCursor();
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

$city = parse_city_key($f['city']);
log_action('export', '', null, "Exported $type CSV ({$f['from']} to {$f['to']}" . ($city ? ", city {$city[1]}, {$city[0]}" : '') . ", $n rows)");
