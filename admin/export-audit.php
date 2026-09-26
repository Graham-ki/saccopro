<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_admin();

// Reuse filters from the audit page
$search = trim((string)($_GET['q'] ?? ''));
$userId = (int)($_GET['user_id'] ?? 0);
$action = trim((string)($_GET['action'] ?? ''));
$entity = trim((string)($_GET['entity'] ?? ''));
$from   = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to     = $_GET['to']   ?? date('Y-m-d');

$where = [];
$params = [];
$where[] = "a.created_at >= :from"; $params[':from'] = $from . ' 00:00:00';
$where[] = "a.created_at <= :to";   $params[':to']   = $to   . ' 23:59:59';

if ($search !== '') {
    $where[] = "(a.details LIKE :s1 OR a.action LIKE :s2 OR u.full_name LIKE :s3)";
    $params[':s1'] = "%$search%";
    $params[':s2'] = "%$search%";
    $params[':s3'] = "%$search%";
}
if ($userId) { $where[] = "a.user_id = :uid"; $params[':uid'] = $userId; }
if ($action !== '') { $where[] = "a.action = :act"; $params[':act'] = $action; }
if ($entity !== '') { $where[] = "a.entity = :ent"; $params[':ent'] = $entity; }

$stmt = $pdo->prepare("
    SELECT a.created_at, u.full_name AS user_name, a.action, a.entity, a.entity_id,
           a.details, a.ip
    FROM activity_log a
    LEFT JOIN users u ON u.id = a.user_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY a.created_at DESC
");
$stmt->execute($params);

$rows = [];
foreach ($stmt->fetchAll() as $r) {
    $rows[] = [
        $r['created_at'],
        $r['user_name'] ?? 'System',
        $r['action'],
        $r['entity'] ?? '',
        $r['entity_id'] ?? '',
        $r['details'] ?? '',
        $r['ip'] ?? '',
    ];
}

$filename = 'audit-log-' . $from . '_to_' . $to . '.csv';
csv_download($filename, ['Timestamp','User','Action','Entity','Entity ID','Details','IP'], $rows);