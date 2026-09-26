<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/shares.php';

$rows = [];
$q = $pdo->query("
    SELECT m.member_no, m.first_name, m.last_name, m.status, m.join_date,
           COALESCE(SUM(CASE WHEN sp.is_reversed = 0 AND (sp.reversal_of_id IS NULL OR sp.reversal_of_id = 0)
                             THEN sp.qty ELSE 0 END), 0) AS shares,
           COALESCE(SUM(CASE WHEN sp.is_reversed = 0 AND (sp.reversal_of_id IS NULL OR sp.reversal_of_id = 0)
                             THEN sp.total_amount ELSE 0 END), 0) AS value
    FROM members m
    LEFT JOIN share_purchases sp ON sp.member_id = m.id
    GROUP BY m.id
    ORDER BY shares DESC, m.last_name, m.first_name
");
foreach ($q->fetchAll() as $r) {
    $rows[] = [
        $r['member_no'], $r['first_name'], $r['last_name'],
        $r['status'], $r['join_date'],
        (int)$r['shares'], number_format((float)$r['value'], 2, '.', ''),
    ];
}

$filename = 'share-register-' . date('Y-m-d') . '.csv';
csv_download($filename, ['Member No','First Name','Last Name','Status','Join Date','Shares','Value'], $rows);