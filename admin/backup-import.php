<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_admin();
require_once __DIR__ . '/../includes/backup-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/scms/admin/backup.php');
}

csrf_check($_POST['csrf'] ?? null);

$errors = [];

// Validate upload
if (empty($_FILES['sqlfile']) || $_FILES['sqlfile']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = 'No file uploaded, or upload failed.';
} elseif ($_FILES['sqlfile']['size'] > 32 * 1024 * 1024) {
    $errors[] = 'File is larger than 32MB.';
} else {
    $tmp = $_FILES['sqlfile']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['sqlfile']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['sql', 'txt'], true)) {
        $errors[] = 'File must be .sql or .txt';
    }
    if (!$errors && !is_uploaded_file($tmp)) {
        $errors[] = 'Security check failed on the uploaded file.';
    }
}

if (empty($_POST['confirm'])) {
    $errors[] = 'You must confirm you understand this overwrites all data.';
}

if ($errors) {
    flash('error', implode(' ', $errors));
    redirect('/scms/admin/backup.php');
}

// Run it
$sql = (string)file_get_contents($tmp);

// Guardrail: only allow files that look like our own dumps
if (!str_contains($sql, 'SCMS database backup')) {
    flash('error', 'This file does not look like an SCMS backup. Refusing to import.');
    redirect('/scms/admin/backup.php');
}

try {
    audit_log($pdo, (int)$user['id'], 'backup.restore.start', null, null,
        'Starting restore from ' . $_FILES['sqlfile']['name']);

    [$count, $importErrors] = run_sql_file($pdo, $sql);

    audit_log($pdo, (int)$user['id'], 'backup.restore.done', null, null,
        "Executed {$count} statements, " . count($importErrors) . ' errors');

    if ($importErrors) {
        flash('error',
            "Restore completed with {$count} statements, but " . count($importErrors) .
            ' error(s) occurred. First: ' . $importErrors[0]);
    } else {
        flash('success', "Restore successful. {$count} statements executed.");
    }

    // Destroy session — user data may have been wiped
    $_SESSION = [];
    session_destroy();
    session_start();
    flash('success', 'Restore complete. Please log in again.');
    redirect('/scms/auth/login.php');

} catch (Throwable $e) {
    error_log('[backup/import] ' . $e->getMessage());
    flash('error', 'Restore failed: ' . $e->getMessage());
    redirect('/scms/admin/backup.php');
}