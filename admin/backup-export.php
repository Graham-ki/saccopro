<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_admin();
require_once __DIR__ . '/../includes/backup-helpers.php';

// Log it
audit_log($pdo, (int)$user['id'], 'backup.export', null, null, 'Downloaded full SQL backup');

$sql = build_backup_sql($pdo);
$filename = 'scms-backup-' . date('Y-m-d-His') . '.sql';

if (!headers_sent()) {
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

echo $sql;
exit;