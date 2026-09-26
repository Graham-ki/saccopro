<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/_helpers.php';

[$from, $to] = report_range();

// Range stats
$stmt = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM members WHERE join_date BETWEEN :from1 AND :to1) AS joined,
        (SELECT COUNT(*) FROM members WHERE status='active')                   AS active,
        (SELECT COUNT(*) FROM members WHERE status='inactive')                 AS inactive,
        (SELECT COUNT(*) FROM members)                                         AS total
");
$stmt->execute([
    ':from1' => $from, ':to1' => $to,
]);
$s = $stmt->fetch();
$stmt->closeCursor();

// Joins per month
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(join_date, '%Y-%m') AS ym, COUNT(*) AS n
    FROM members
    WHERE join_date BETWEEN :from1 AND :to1
    GROUP BY ym ORDER BY ym
");
$stmt->execute([
    ':from1' => $from, ':to1' => $to,
]);
$joinsByMonth = $stmt->fetchAll();
$stmt->closeCursor();

// Top savers
$topSavers = $pdo->query("
    SELECT m.id, m.first_name, m.last_name, m.member_no,
           sa.balance, sa.account_no
    FROM savings_accounts sa
    JOIN members m ON m.id = sa.member_id
    ORDER BY sa.balance DESC
    LIMIT 10
")->fetchAll();

// Top borrowers
$topBorrowers = $pdo->query("
    SELECT m.id, m.first_name, m.last_name, m.member_no,
           COUNT(l.id) AS active_loans,
           COALESCE(SUM(l.balance), 0) AS outstanding
    FROM loans l
    JOIN members m ON m.id = l.member_id
    WHERE l.status = 'active'
    GROUP BY m.id
    ORDER BY outstanding DESC
    LIMIT 10
")->fetchAll();

$pageTitle = 'Members Report';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.dash-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
@media (max-width:900px) { .dash-grid { grid-template-columns:1fr; } }
@media print {
    .sidebar,.topbar,.page-head-actions,.tabs,form.card { display:none !important; }
    .main { margin-left:0 !important; } .page { padding:0 !important; }
    .card,.table-wrap { box-shadow:none !important; border-color:#ccc !important; }
}
</style>
<div class="page-head">
    <div>
        <h1>Members report</h1>
        <p><?= e(date('M j, Y', strtotime($from))) ?> → <?= e(date('M j, Y', strtotime($to))) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="export.php?report=members&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-outline">⬇ CSV</a>
    </div>
</div>

<nav class="tabs" style="margin-bottom:18px;">
    <a href="index.php" class="tab">Overview</a>
    <a href="members.php" class="tab active">Members</a>
    <a href="savings.php" class="tab">Savings</a>
    <a href="loans.php" class="tab">Loans</a>
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
        <div class="stat-label">New joins (range)</div>
        <div class="stat-value"><?= (int)$s['joined'] ?></div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-label">Active members</div>
        <div class="stat-value"><?= (int)$s['active'] ?></div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-label">Inactive members</div>
        <div class="stat-value"><?= (int)$s['inactive'] ?></div>
    </div>
    <div class="stat animate-fade-up delay-3">
        <div class="stat-label">Total members</div>
        <div class="stat-value"><?= (int)$s['total'] ?></div>
    </div>
</div>

<div class="dash-grid" style="margin-top:18px;">
    <div class="card animate-fade-up">
        <div class="card-title" style="margin-bottom:14px;">Top savers</div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>Member</th><th>Account</th><th style="text-align:right;">Balance</th></tr></thead>
                <tbody>
                <?php foreach ($topSavers as $i => $t): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <a href="/scms/members/view.php?id=<?= (int)$t['id'] ?>" style="font-weight:600;">
                                <?= e($t['first_name'] . ' ' . $t['last_name']) ?>
                            </a>
                            <div class="muted" style="font-size:.76rem;"><code><?= e($t['member_no']) ?></code></div>
                        </td>
                        <td><code style="font-size:.8rem;"><?= e($t['account_no']) ?></code></td>
                        <td style="text-align:right;font-weight:700;"><?= e(money((float)$t['balance'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$topSavers): ?>
                    <tr><td colspan="4" class="center muted" style="padding:32px;">No savings accounts yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card animate-fade-up delay-1">
        <div class="card-title" style="margin-bottom:14px;">Top borrowers</div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>Member</th><th style="text-align:right;">Loans</th><th style="text-align:right;">Outstanding</th></tr></thead>
                <tbody>
                <?php foreach ($topBorrowers as $i => $t): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <a href="/scms/members/view.php?id=<?= (int)$t['id'] ?>" style="font-weight:600;">
                                <?= e($t['first_name'] . ' ' . $t['last_name']) ?>
                            </a>
                            <div class="muted" style="font-size:.76rem;"><code><?= e($t['member_no']) ?></code></div>
                        </td>
                        <td style="text-align:right;"><?= (int)$t['active_loans'] ?></td>
                        <td style="text-align:right;font-weight:700;"><?= e(money((float)$t['outstanding'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$topBorrowers): ?>
                    <tr><td colspan="4" class="center muted" style="padding:32px;">No active loans.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card animate-fade-up delay-2" style="margin-top:18px;">
    <div class="card-title" style="margin-bottom:14px;">New members by month</div>
    <?php if ($joinsByMonth): ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Month</th><th style="text-align:right;">New joins</th></tr></thead>
                <tbody>
                    <?php foreach ($joinsByMonth as $j): ?>
                        <tr>
                            <td><?= e(date('F Y', strtotime($j['ym'] . '-01'))) ?></td>
                            <td style="text-align:right;font-weight:600;"><?= (int)$j['n'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="center muted" style="padding:32px;">No new members in this period.</div>
    <?php endif; ?>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>