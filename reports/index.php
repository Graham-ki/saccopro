<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/_helpers.php';

[$from, $to] = report_range();

// ---- KPIs in range (named params, reused safely) ----
$stmt = $pdo->prepare("
    SELECT
        (SELECT COALESCE(SUM(amount), 0) FROM savings_transactions
         WHERE type='deposit'    AND transaction_date BETWEEN :from1 AND :to1) AS deposits,
        (SELECT COALESCE(SUM(amount), 0) FROM savings_transactions
         WHERE type='withdrawal' AND transaction_date BETWEEN :from2 AND :to2) AS withdrawals,
        (SELECT COUNT(*) FROM members
         WHERE join_date BETWEEN :from3 AND :to3)                              AS new_members,
        (SELECT COUNT(*) FROM loans
         WHERE issue_date BETWEEN :from4 AND :to4)                             AS new_loans,
        (SELECT COALESCE(SUM(principal), 0) FROM loans
         WHERE issue_date BETWEEN :from5 AND :to5)                             AS loaned_principal,
        (SELECT COALESCE(SUM(amount), 0) FROM loan_repayments
         WHERE payment_date BETWEEN :from6 AND :to6)                           AS repayments_received,
        (SELECT COALESCE(SUM(interest_portion), 0) FROM loan_repayments
         WHERE payment_date BETWEEN :from7 AND :to7)                           AS interest_earned
");
$stmt->execute([
    ':from1' => $from, ':to1' => $to,
    ':from2' => $from, ':to2' => $to,
    ':from3' => $from, ':to3' => $to,
    ':from4' => $from, ':to4' => $to,
    ':from5' => $from, ':to5' => $to,
    ':from6' => $from, ':to6' => $to,
    ':from7' => $from, ':to7' => $to,
]);
$k = $stmt->fetch();
$stmt->closeCursor();

$netFlow = (float)$k['deposits'] - (float)$k['withdrawals'];

// ---- Overall balances ----
$overall = $pdo->query("
    SELECT
        (SELECT COALESCE(SUM(balance), 0) FROM savings_accounts)             AS total_savings,
        (SELECT COUNT(*) FROM members)                                        AS total_members,
        (SELECT COUNT(*) FROM members WHERE status='active')                  AS active_members,
        (SELECT COALESCE(SUM(balance), 0) FROM loans WHERE status='active')   AS loans_outstanding,
        (SELECT COUNT(*) FROM loans WHERE status='active')                    AS active_loans
")->fetch();

// ---- Monthly trend ----
$trend = $pdo->query("
    SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS ym,
           SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END)     AS dep,
           SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END)  AS wd
    FROM savings_transactions
    WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY ym
    ORDER BY ym ASC
")->fetchAll();

$maxTrend = 0.0;
foreach ($trend as $t) {
    $maxTrend = max($maxTrend, (float)$t['dep'], (float)$t['wd']);
}
if ($maxTrend <= 0) $maxTrend = 1;

// ---- Loan portfolio by status ----
$portfolio = $pdo->query("
    SELECT status, COUNT(*) AS n, COALESCE(SUM(balance), 0) AS bal
    FROM loans GROUP BY status ORDER BY n DESC
")->fetchAll();

$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.kpi-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:18px; margin-bottom:24px; }
.stat-hero { grid-column:span 2; background:linear-gradient(135deg,var(--surface) 0%,var(--surface-2) 100%); border-color:var(--primary) !important; position:relative; overflow:hidden; }
.stat-hero::after { content:''; position:absolute; top:-40%; right:-20%; width:260px; height:260px; background:radial-gradient(circle,rgba(79,70,229,.18),transparent 70%); border-radius:50%; pointer-events:none; }
.stat-hero-value { font-size:2.4rem; font-weight:800; letter-spacing:-0.02em; line-height:1.1; margin:8px 0 4px; background:linear-gradient(135deg,var(--primary),var(--accent)); -webkit-background-clip:text; background-clip:text; color:transparent; }
.stat-icon-hero { width:56px; height:56px; background:linear-gradient(135deg,var(--primary),var(--accent)); color:#fff; font-size:1.5rem; box-shadow:0 8px 20px rgba(79,70,229,.35); }
.stat-hero-meta { margin-top:14px; font-size:.85rem; color:var(--text-2); }
.stat-hero-meta strong { color:var(--text); }
.dash-grid { display:grid; grid-template-columns:1.6fr 1fr; gap:18px; }

.chart { display:flex; align-items:flex-end; gap:8px; height:240px; padding:12px 4px 0; }
.chart-col { flex:1; display:flex; flex-direction:column; align-items:center; gap:6px; height:100%; }
.chart-bars { flex:1; display:flex; align-items:flex-end; gap:3px; width:100%; }
.chart-bar { flex:1; border-radius:3px 3px 0 0; min-height:2px; transition:opacity .2s; }
.chart-bar-dep { background:linear-gradient(180deg,var(--accent),var(--accent-h)); }
.chart-bar-wd  { background:linear-gradient(180deg,var(--danger),#b91c1c); }
.chart-col:hover .chart-bar { opacity:.75; }
.chart-label { font-size:.72rem; color:var(--text-3); }

@media (max-width:1100px) {
    .kpi-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .stat-hero { grid-column:span 2; }
    .dash-grid { grid-template-columns:1fr; }
}
@media (max-width:640px) {
    .kpi-grid { grid-template-columns:1fr; }
    .stat-hero { grid-column:span 1; }
    .stat-hero-value { font-size:1.8rem; }
    .chart { height:180px; }
}
@media print {
    .sidebar,.topbar,.page-head-actions,.tabs,form.card { display:none !important; }
    .main { margin-left:0 !important; }
    .page { padding:0 !important; }
    .card,.table-wrap { box-shadow:none !important; border-color:#ccc !important; }
}
</style>
<div class="page-head">
    <div>
        <h1>Reports</h1>
        <p>Overview of activity from <?= e(date('M j, Y', strtotime($from))) ?> to <?= e(date('M j, Y', strtotime($to))) ?>.</p>
    </div>
    <div class="page-head-actions">
        <a href="export.php?report=overview&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-outline">⬇ Download CSV</a>
    </div>
</div>

<nav class="tabs" style="margin-bottom:18px;">
    <a href="index.php" class="tab active">Overview</a>
    <a href="members.php" class="tab">Members</a>
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
    <div style="margin-left:auto;display:flex;gap:8px;align-self:flex-end;">
        <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-ghost btn-sm">This month</a>
        <a href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-ghost btn-sm">This year</a>
        <a href="?from=<?= date('Y-m-d', strtotime('-30 days')) ?>&to=<?= date('Y-m-d') ?>" class="btn btn-ghost btn-sm">Last 30d</a>
    </div>
</form>

<div class="kpi-grid">
    <div class="stat stat-hero animate-fade-up">
        <div class="stat-head">
            <div>
                <div class="stat-label">Total savings held</div>
                <div class="stat-hero-value"><?= e(money((float)$overall['total_savings'])) ?></div>
            </div>
            <div class="stat-icon stat-icon-hero">💰</div>
        </div>
        <div class="stat-hero-meta">
            <strong><?= (int)$overall['active_members'] ?></strong> active ·
            <strong><?= (int)$overall['total_members'] ?></strong> total members
        </div>
    </div>

    <div class="stat animate-fade-up delay-1">
        <div class="stat-head"><div><div class="stat-label">Net flow (range)</div></div><div class="stat-icon">📈</div></div>
        <div class="stat-value" style="color:<?= $netFlow >= 0 ? 'var(--accent)' : 'var(--danger)' ?>;">
            <?= e(money($netFlow)) ?>
        </div>
        <div class="stat-trend muted">Dep <?= e(money((float)$k['deposits'])) ?> · Wd <?= e(money((float)$k['withdrawals'])) ?></div>
    </div>

    <div class="stat animate-fade-up delay-2">
        <div class="stat-head"><div><div class="stat-label">Loans outstanding</div></div><div class="stat-icon">📄</div></div>
        <div class="stat-value"><?= e(money((float)$overall['loans_outstanding'])) ?></div>
        <div class="stat-trend muted"><?= (int)$overall['active_loans'] ?> active loans</div>
    </div>

    <div class="stat animate-fade-up delay-3">
        <div class="stat-head"><div><div class="stat-label">Interest earned (range)</div></div><div class="stat-icon">💵</div></div>
        <div class="stat-value"><?= e(money((float)$k['interest_earned'])) ?></div>
        <div class="stat-trend muted">From <?= e(money((float)$k['repayments_received'])) ?> in repayments</div>
    </div>
</div>

<div class="dash-grid">
    <div class="card animate-fade-up delay-4">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
            <div>
                <div class="card-title">Savings flow — last 12 months</div>
                <div class="card-sub">Deposits vs withdrawals</div>
            </div>
            <div style="display:flex;gap:14px;font-size:.8rem;">
                <span style="display:flex;align-items:center;gap:6px;">
                    <span style="width:10px;height:10px;background:var(--accent);border-radius:2px;"></span>Deposits
                </span>
                <span style="display:flex;align-items:center;gap:6px;">
                    <span style="width:10px;height:10px;background:var(--danger);border-radius:2px;"></span>Withdrawals
                </span>
            </div>
        </div>

        <?php if ($trend): ?>
            <div class="chart">
                <?php foreach ($trend as $t): ?>
                    <?php
                        $depH = ((float)$t['dep'] / $maxTrend) * 100;
                        $wdH  = ((float)$t['wd'] / $maxTrend) * 100;
                        $label = date('M', strtotime($t['ym'] . '-01'));
                    ?>
                    <div class="chart-col">
                        <div class="chart-bars">
                            <div class="chart-bar chart-bar-dep" style="height:<?= number_format($depH, 2) ?>%"></div>
                            <div class="chart-bar chart-bar-wd" style="height:<?= number_format($wdH, 2) ?>%"></div>
                        </div>
                        <div class="chart-label"><?= e($label) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="center muted" style="padding:40px;">No savings activity in the last 12 months.</div>
        <?php endif; ?>
    </div>

    <div class="card animate-fade-up delay-5">
        <div class="card-title" style="margin-bottom:14px;">Loan portfolio</div>
        <?php if ($portfolio): ?>
            <div style="display:flex;flex-direction:column;gap:12px;">
                <?php foreach ($portfolio as $p): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid var(--border-2);border-radius:10px;">
                        <div>
                            <span class="badge badge-<?= $p['status'] === 'active' ? 'info' : ($p['status'] === 'completed' ? 'approved' : 'rejected') ?>">
                                <?= e(ucfirst($p['status'])) ?>
                            </span>
                            <div class="muted" style="font-size:.78rem;margin-top:6px;"><?= (int)$p['n'] ?> loan<?= (int)$p['n'] === 1 ? '' : 's' ?></div>
                        </div>
                        <div style="font-weight:700;"><?= e(money((float)$p['bal'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="center muted" style="padding:40px;">No loans recorded yet.</div>
        <?php endif; ?>
    </div>
</div>

<div class="stats-grid" style="margin-top:18px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
    <a href="members.php" class="card animate-fade-up" style="text-decoration:none;color:inherit;">
        <div class="card-title">👥 Members report</div>
        <div class="card-sub" style="margin-top:6px;">Joins, activity, top savers</div>
    </a>
    <a href="savings.php" class="card animate-fade-up delay-1" style="text-decoration:none;color:inherit;">
        <div class="card-title">💰 Savings report</div>
        <div class="card-sub" style="margin-top:6px;">Flow, balances, largest txns</div>
    </a>
    <a href="loans.php" class="card animate-fade-up delay-2" style="text-decoration:none;color:inherit;">
        <div class="card-title">📄 Loans report</div>
        <div class="card-sub" style="margin-top:6px;">Aging, portfolio, collections</div>
    </a>
    <a href="income.php" class="card animate-fade-up delay-3" style="text-decoration:none;color:inherit;">
        <div class="card-title">💵 Income report</div>
        <div class="card-sub" style="margin-top:6px;">Interest earned & projected</div>
    </a>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>