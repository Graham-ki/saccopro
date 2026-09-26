<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/_helpers.php';

[$from, $to] = report_range();

// Interest collected in range
$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(interest_portion), 0)  AS interest,
        COALESCE(SUM(principal_portion), 0) AS principal,
        COALESCE(SUM(amount), 0)            AS total,
        COUNT(*)                            AS n
    FROM loan_repayments
    WHERE payment_date BETWEEN :from1 AND :to1
");
$stmt->execute([':from1' => $from, ':to1' => $to]);
$s = $stmt->fetch();
$stmt->closeCursor();

// Projected future interest on active loans
$projected = $pdo->query("
    SELECT COALESCE(SUM(ls.interest_due - (ls.amount_paid *
        CASE WHEN ls.total_due > 0 THEN ls.interest_due / ls.total_due ELSE 0 END)), 0)
    FROM loan_schedules ls
    JOIN loans l ON l.id = ls.loan_id
    WHERE l.status = 'active'
      AND ls.status IN ('pending','partial','overdue')
")->fetchColumn();

// Monthly income
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(payment_date, '%Y-%m') AS ym,
           SUM(interest_portion)  AS interest,
           SUM(principal_portion) AS principal,
           SUM(amount)            AS total
    FROM loan_repayments
    WHERE payment_date BETWEEN :from1 AND :to1
    GROUP BY ym ORDER BY ym
");
$stmt->execute([':from1' => $from, ':to1' => $to]);
$monthly = $stmt->fetchAll();
$stmt->closeCursor();

// Top interest payers
$stmt = $pdo->prepare("
    SELECT m.id, m.first_name, m.last_name, m.member_no,
           SUM(r.interest_portion) AS interest_paid
    FROM loan_repayments r
    JOIN loans l ON l.id = r.loan_id
    JOIN members m ON m.id = l.member_id
    WHERE r.payment_date BETWEEN :from1 AND :to1
    GROUP BY m.id
    ORDER BY interest_paid DESC
    LIMIT 10
");
$stmt->execute([':from1' => $from, ':to1' => $to]);
$topPayers = $stmt->fetchAll();
$stmt->closeCursor();

$pageTitle = 'Income Report';
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
        <h1>Income report</h1>
        <p><?= e(date('M j, Y', strtotime($from))) ?> → <?= e(date('M j, Y', strtotime($to))) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="export.php?report=income&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-outline">⬇ CSV</a>
    </div>
</div>

<nav class="tabs" style="margin-bottom:18px;">
    <a href="index.php" class="tab">Overview</a>
    <a href="members.php" class="tab">Members</a>
    <a href="savings.php" class="tab">Savings</a>
    <a href="loans.php" class="tab">Loans</a>
    <a href="income.php" class="tab active">Income</a>
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
        <div class="stat-label">Interest earned (range)</div>
        <div class="stat-value" style="color:var(--accent);"><?= e(money((float)$s['interest'])) ?></div>
        <div class="stat-trend muted"><?= (int)$s['n'] ?> repayments</div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-label">Principal recovered</div>
        <div class="stat-value"><?= e(money((float)$s['principal'])) ?></div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-label">Total collected</div>
        <div class="stat-value"><?= e(money((float)$s['total'])) ?></div>
    </div>
    <div class="stat animate-fade-up delay-3">
        <div class="stat-label">Projected future interest</div>
        <div class="stat-value"><?= e(money((float)$projected)) ?></div>
        <div class="stat-trend muted">On active loans</div>
    </div>
</div>

<div class="card animate-fade-up delay-4" style="margin-top:18px;">
    <div class="card-title" style="margin-bottom:14px;">Monthly income breakdown</div>
    <?php if ($monthly): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th style="text-align:right;">Interest</th>
                        <th style="text-align:right;">Principal</th>
                        <th style="text-align:right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $tI = 0; $tP = 0; $tT = 0;
                foreach ($monthly as $m):
                    $tI += (float)$m['interest'];
                    $tP += (float)$m['principal'];
                    $tT += (float)$m['total'];
                ?>
                    <tr>
                        <td><?= e(date('F Y', strtotime($m['ym'] . '-01'))) ?></td>
                        <td style="text-align:right;color:var(--accent);font-weight:600;"><?= e(money((float)$m['interest'])) ?></td>
                        <td style="text-align:right;"><?= e(money((float)$m['principal'])) ?></td>
                        <td style="text-align:right;font-weight:600;"><?= e(money((float)$m['total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="background:var(--surface-2);border-top:2px solid var(--border);">
                        <td style="font-weight:700;">Total</td>
                        <td style="text-align:right;font-weight:700;color:var(--accent);"><?= e(money($tI)) ?></td>
                        <td style="text-align:right;font-weight:700;"><?= e(money($tP)) ?></td>
                        <td style="text-align:right;font-weight:800;"><?= e(money($tT)) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="center muted" style="padding:32px;">No income in this period.</div>
    <?php endif; ?>
</div>

<div class="card animate-fade-up delay-5" style="margin-top:18px;">
    <div class="card-title" style="margin-bottom:14px;">Top interest payers</div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>#</th><th>Member</th><th style="text-align:right;">Interest paid</th></tr></thead>
            <tbody>
            <?php foreach ($topPayers as $i => $p): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td>
                        <a href="/scms/members/view.php?id=<?= (int)$p['id'] ?>" style="font-weight:600;">
                            <?= e($p['first_name'] . ' ' . $p['last_name']) ?>
                        </a>
                        <div class="muted" style="font-size:.76rem;"><code><?= e($p['member_no']) ?></code></div>
                    </td>
                    <td style="text-align:right;font-weight:700;"><?= e(money((float)$p['interest_paid'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$topPayers): ?>
                <tr><td colspan="3" class="center muted" style="padding:32px;">No interest collected in this period.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>