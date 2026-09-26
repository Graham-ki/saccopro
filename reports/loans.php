<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/_helpers.php';

[$from, $to] = report_range();

// Range activity
$stmt = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM loans WHERE issue_date BETWEEN :from1 AND :to1)                    AS issued_n,
        (SELECT COALESCE(SUM(principal),0) FROM loans WHERE issue_date BETWEEN :from2 AND :to2)  AS issued_amt,
        (SELECT COALESCE(SUM(amount),0) FROM loan_repayments
         WHERE payment_date BETWEEN :from3 AND :to3)                                             AS collected,
        (SELECT COUNT(*) FROM loans WHERE status='completed')                                    AS completed_total,
        (SELECT COUNT(*) FROM loans WHERE status='active')                                       AS active_total,
        (SELECT COALESCE(SUM(balance),0) FROM loans WHERE status='active')                       AS outstanding
");
$stmt->execute([
    ':from1' => $from, ':to1' => $to,
    ':from2' => $from, ':to2' => $to,
    ':from3' => $from, ':to3' => $to,
]);
$s = $stmt->fetch();
$stmt->closeCursor();

// Aging buckets (active loans)
$buckets = ['Not yet due'=>0, 'Due today'=>0, '1–30 days'=>0, '31–60 days'=>0, '61–90 days'=>0, '90+ days'=>0];
$bucketsAmount = $buckets;

$agingRows = $pdo->query("
    SELECT ls.due_date, ls.total_due, ls.status, ls.amount_paid, l.status AS loan_status
    FROM loan_schedules ls
    JOIN loans l ON l.id = ls.loan_id
    WHERE ls.status IN ('pending','partial','overdue') AND l.status='active'
")->fetchAll();

foreach ($agingRows as $r) {
    $bucket = aging_bucket($r['due_date'], $r['status']);
    if (!isset($buckets[$bucket])) continue;
    $buckets[$bucket]++;
    $bucketsAmount[$bucket] += max(0, (float)$r['total_due'] - (float)$r['amount_paid']);
}

// Portfolio by status
$portfolio = $pdo->query("
    SELECT status, COUNT(*) AS n, COALESCE(SUM(principal),0) AS principal, COALESCE(SUM(balance),0) AS bal
    FROM loans GROUP BY status
")->fetchAll();

$pageTitle = 'Loans Report';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
@media print {
    .sidebar,.topbar,.page-head-actions,.tabs,form.card { display:none !important; }
    .main { margin-left:0 !important; } .page { padding:0 !important; }
    .card,.table-wrap { box-shadow:none !important; border-color:#ccc !important; }
}
</style>
<div class="page-head">
    <div>
        <h1>Loans report</h1>
        <p><?= e(date('M j, Y', strtotime($from))) ?> → <?= e(date('M j, Y', strtotime($to))) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="export.php?report=loans&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-outline">⬇ CSV</a>
    </div>
</div>

<nav class="tabs" style="margin-bottom:18px;">
    <a href="index.php" class="tab">Overview</a>
    <a href="members.php" class="tab">Members</a>
    <a href="savings.php" class="tab">Savings</a>
    <a href="loans.php" class="tab active">Loans</a>
    <a href="income.php" class="tab">Income</a>
</nav>

<form method="get" class="card" style="padding:14px 18px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:18px;">
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        From
        <input type="date" name="from" value="<?= e($from) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        To
        <input type="date" name="to" value="<?= e($to) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </label>
    <button class="btn btn-outline btn-sm" type="submit" style="align-self:flex-end;">Apply</button>
</form>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
    <div class="stat animate-fade-up">
        <div class="stat-label">Loans issued (range)</div>
        <div class="stat-value"><?= (int)$s['issued_n'] ?></div>
        <div class="stat-trend muted"><?= e(money((float)$s['issued_amt'])) ?> principal</div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-label">Repayments collected</div>
        <div class="stat-value" style="color:var(--accent);"><?= e(money((float)$s['collected'])) ?></div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-label">Active loans</div>
        <div class="stat-value"><?= (int)$s['active_total'] ?></div>
        <div class="stat-trend muted"><?= e(money((float)$s['outstanding'])) ?> outstanding</div>
    </div>
    <div class="stat animate-fade-up delay-3">
        <div class="stat-label">Completed loans</div>
        <div class="stat-value"><?= (int)$s['completed_total'] ?></div>
    </div>
</div>

<div class="card animate-fade-up delay-4" style="margin-top:18px;">
    <div class="card-title" style="margin-bottom:14px;">Aging schedule (active loans)</div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Bucket</th><th style="text-align:right;">Installments</th><th style="text-align:right;">Amount due</th></tr>
            </thead>
            <tbody>
            <?php foreach ($buckets as $label => $n): ?>
                <tr>
                    <td>
                        <?php if ($label === '90+ days' || $label === '61–90 days'): ?>
                            <span class="badge badge-rejected"><?= e($label) ?></span>
                        <?php elseif ($label === '31–60 days' || $label === 'Due today'): ?>
                            <span class="badge badge-pending"><?= e($label) ?></span>
                        <?php else: ?>
                            <span class="badge badge-approved"><?= e($label) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;font-weight:600;"><?= (int)$n ?></td>
                    <td style="text-align:right;font-weight:600;"><?= e(money($bucketsAmount[$label])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card animate-fade-up delay-5" style="margin-top:18px;">
    <div class="card-title" style="margin-bottom:14px;">Portfolio by status</div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Status</th><th style="text-align:right;">Loans</th><th style="text-align:right;">Principal</th><th style="text-align:right;">Outstanding</th></tr>
            </thead>
            <tbody>
            <?php foreach ($portfolio as $p): ?>
                <tr>
                    <td>
                        <span class="badge badge-<?= $p['status'] === 'active' ? 'info' : ($p['status'] === 'completed' ? 'approved' : 'rejected') ?>">
                            <?= e(ucfirst($p['status'])) ?>
                        </span>
                    </td>
                    <td style="text-align:right;"><?= (int)$p['n'] ?></td>
                    <td style="text-align:right;"><?= e(money((float)$p['principal'])) ?></td>
                    <td style="text-align:right;font-weight:600;"><?= e(money((float)$p['bal'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$portfolio): ?>
                <tr><td colspan="4" class="center muted" style="padding:32px;">No loans.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>