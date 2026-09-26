<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']); exit;
}

csrf_check($_POST['csrf'] ?? null);

$principal   = (float)($_POST['principal'] ?? 0);
$rate        = (float)($_POST['interest_rate'] ?? 0);
$term        = (int)($_POST['term_months'] ?? 0);
$method      = $_POST['interest_method'] ?? 'declining';
$firstDue    = $_POST['first_due_date'] ?? '';

if (!in_array($method, ['flat','declining'], true)) $method = 'declining';

try {
    $sched = build_loan_schedule($principal, $rate, $term, $firstDue ?: date('Y-m-d'), $method);
    echo json_encode($sched);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}