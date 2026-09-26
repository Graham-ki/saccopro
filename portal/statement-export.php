<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_member();
$member = current_member();
$memberId = (int)$member['id'];

$accountId = (int)($member['account_id'] ?? 0);
if (!$accountId) {
    flash('error', 'No savings account.');
    redirect('/scms/portal/index.php');
}

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');
$valid = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$valid($from)) $from = date('Y-m-01');
if (!$valid($to))   $to = date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT transaction_date, reference, type, amount, balance_after, notes
    FROM savings_transactions
    WHERE account_id = ? AND transaction_date BETWEEN ? AND ?
    ORDER BY transaction_date ASC, id ASC
");
$stmt->execute([$accountId, $from, $to]);

$rows = [];
foreach ($stmt->fetchAll() as $r) {
    $rows[] = [
        $r['transaction_date'],
        $r['reference'],
        ucfirst($r['type']),
        number_format((float)$r['amount'], 2, '.', ''),
        number_format((float)$r['balance_after'], 2, '.', ''),
        $r['notes'] ?? '',
    ];
}

$filename = 'my-statement-' . $from . '_to_' . $to . '.csv';
csv_download($filename, ['Date','Reference','Type','Amount','Balance','Notes'], $rows);