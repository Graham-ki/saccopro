<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_member();
$member = current_member();
require_once __DIR__ . '/../includes/shares.php';

$memberId = (int)$member['id'];

// Savings totals
$savings = $pdo->prepare("
    SELECT
        COALESCE(sa.balance, 0) AS balance,
        sa.account_no,
        (SELECT COALESCE(SUM(amount), 0) FROM savings_transactions st
         WHERE st.account_id = sa.id AND st.type='deposit'
           AND st.is_reversed = 0 AND (st.reversal_of_id IS NULL OR st.reversal_of_id = 0)) AS total_deposits,
        (SELECT COALESCE(SUM(amount), 0) FROM savings_transactions st
         WHERE st.account_id = sa.id AND st.type='withdrawal'
           AND st.is_reversed = 0 AND (st.reversal_of_id IS NULL OR st.reversal_of_id = 0)) AS total_withdrawals
    FROM savings_accounts sa
    WHERE sa.member_id = ? LIMIT 1
");
$savings->execute([$memberId]);
$savings = $savings->fetch() ?: ['balance' => 0, 'account_no' => '—', 'total_deposits' => 0, 'total_withdrawals' => 0];

// Loans
$loans = $pdo->prepare("
    SELECT
        SUM(status='active') AS active,
        SUM(status='completed') AS completed,
        COALESCE(SUM(CASE WHEN status='active' THEN balance END), 0) AS outstanding
    FROM loans WHERE member_id = ?
");
$loans->execute([$memberId]);
$loanStats = $loans->fetch();

$nextDue = $pdo->prepare("
    SELECT ls.*, l.loan_no, l.id AS loan_id
    FROM loan_schedules ls
    JOIN loans l ON l.id = ls.loan_id
    WHERE l.member_id = ? AND l.status = 'active'
      AND ls.status IN ('pending','partial','overdue')
    ORDER BY ls.due_date ASC LIMIT 1
");
$nextDue->execute([$memberId]);
$nextDue = $nextDue->fetch();

// Shares
$shares = member_shares_held($pdo, $memberId);
$faceValue = (float)(setting('share_face_value') ?? '10000');
$sharesValue = $shares * $faceValue;

// Dividends
$divTotal = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM dividend_payouts
    WHERE member_id = ? AND paid_at IS NOT NULL
");
$divTotal->execute([$memberId]);
$dividendsReceived = (float)$divTotal->fetchColumn();

// Pending requests
$pendingRequests = $pdo->prepare("
    SELECT * FROM member_requests
    WHERE member_id = ? AND status = 'pending'
    ORDER BY created_at DESC
");
$pendingRequests->execute([$memberId]);
$pendingRequests = $pendingRequests->fetchAll();

// Savings trend (last 6 months)
$trend = $pdo->prepare("
    SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS ym,
           SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END) AS dep,
           SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END) AS wd
    FROM savings_transactions
    WHERE account_id = (SELECT id FROM savings_accounts WHERE member_id = ? LIMIT 1)
      AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
      AND is_reversed = 0
      AND (reversal_of_id IS NULL OR reversal_of_id = 0)
    GROUP BY ym ORDER BY ym
");
$trend->execute([$memberId]);
$trend = $trend->fetchAll();

$maxTrend = 1;
foreach ($trend as $t) {
    $maxTrend = max($maxTrend, (float)$t['dep'], (float)$t['wd']);
}

// Recent savings transactions
$recent = $pdo->prepare("
    SELECT st.* FROM savings_transactions st
    WHERE st.account_id = (SELECT id FROM savings_accounts WHERE member_id = ? LIMIT 1)
    ORDER BY st.transaction_date DESC, st.id DESC
    LIMIT 6
");
$recent->execute([$memberId]);
$recent = $recent->fetchAll();

$pageTitle = 'My Account';
require_once __DIR__ . '/../includes/header.php';

$initial = strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1));
?>
<style>
.kpi-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:18px; margin-bottom:18px; }
.kpi-grid:has(.stat-hero) { grid-template-columns:1fr; }
.stat-hero { background:linear-gradient(135deg,var(--surface) 0%,var(--surface-2) 100%); border:1px solid var(--primary) !important; border-radius:var(--radius); position:relative; overflow:hidden; padding:28px; }
.stat-hero::after { content:''; position:absolute; top:-40%; right:-20%; width:260px; height:260px; background:radial-gradient(circle,rgba(79,70,229,.18),transparent 70%); border-radius:50%; pointer-events:none; }
.stat-hero-value { font-size:2.4rem; font-weight:800; letter-spacing:-0.02em; line-height:1.1; margin:8px 0 4px; background:linear-gradient(135deg,var(--primary),var(--accent)); -webkit-background-clip:text; background-clip:text; color:transparent; }
.stat-icon-hero { width:56px; height:56px; border-radius:14px; display:grid; place-items:center; background:linear-gradient(135deg,var(--primary),var(--accent)); color:#fff; font-size:1.5rem; box-shadow:0 8px 20px rgba(79,70,229,.35); }
.stat-hero-meta { margin-top:8px; font-size:.85rem; color:var(--text-2); }
.stat-hero-split { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-top:22px; padding-top:18px; border-top:1px solid var(--border-2); }
.dash-grid { display:grid; grid-template-columns:1.5fr 1fr; gap:18px; }
.chart { display:flex; align-items:flex-end; gap:8px; height:220px; padding:12px 4px 0; }
.chart-col { flex:1; display:flex; flex-direction:column; align-items:center; gap:6px; height:100%; }
.chart-bars { flex:1; display:flex; align-items:flex-end; gap:3px; width:100%; }
.chart-bar { flex:1; border-radius:3px 3px 0 0; min-height:2px; }
.chart-bar-dep { background:linear-gradient(180deg,var(--accent),var(--accent-h)); }
.chart-bar-wd { background:linear-gradient(180deg,var(--danger),#b91c1c); }
.chart-label { font-size:.72rem; color:var(--text-3); }
@media (max-width:1100px) {
    .kpi-grid { grid-template-columns:repeat(2,minmax(0,1fr)) !important; }
    .dash-grid { grid-template-columns:1fr; }
}
@media (max-width:640px) {
    .kpi-grid { grid-template-columns:1fr !important; }
    .stat-hero-value { font-size:2rem; }
    .stat-hero-split { grid-template-columns:1fr; }
}
</style>
<div class="page-head">
    <div style="display:flex;align-items:center;gap:16px;">
        <div class="avatar" style="width:56px;height:56px;font-size:1.2rem;border-radius:14px;"><?= e($initial) ?></div>
        <div>
            <h1 style="margin-bottom:2px;">Hello, <?= e($member['first_name']) ?> 👋</h1>
            <p style="margin:0;">
                <code><?= e($member['member_no']) ?></code> · Member portal
            </p>
        </div>
    </div>
    <div class="page-head-actions">
        <a href="request-deposit.php" class="btn btn-outline">+ Deposit</a>
        <a href="request-loan.php" class="btn btn-primary">Request loan</a>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($pendingRequests): ?>
    <div class="alert alert-info">
        <strong>You have <?= count($pendingRequests) ?> pending request<?= count($pendingRequests) === 1 ? '' : 's' ?> awaiting approval.</strong>
        <a href="requests.php" style="margin-left:8px;font-weight:600;">View →</a>
    </div>
<?php endif; ?>

<div class="kpi-grid">
    <div class="stat stat-hero animate-fade-up">
        <div class="stat-head">
            <div>
                <div class="stat-label">Savings balance</div>
                <div class="stat-hero-value"><?= e(money((float)$savings['balance'])) ?></div>
                <div class="stat-hero-meta">Account <?= e($savings['account_no']) ?></div>
            </div>
            <div class="stat-icon stat-icon-hero">💰</div>
        </div>
        <div class="stat-hero-split">
            <div>
                <div class="muted" style="font-size:.72rem;text-transform:uppercase;">Total in</div>
                <div style="font-weight:700;color:var(--accent);">+<?= e(money((float)$savings['total_deposits'])) ?></div>
            </div>
            <div>
                <div class="muted" style="font-size:.72rem;text-transform:uppercase;">Total out</div>
                <div style="font-weight:700;color:var(--danger);">−<?= e(money((float)$savings['total_withdrawals'])) ?></div>
            </div>
            <div>
                <div class="muted" style="font-size:.72rem;text-transform:uppercase;">Shares held</div>
                <div style="font-weight:700;"><?= number_format($shares) ?> (<?= e(money($sharesValue)) ?>)</div>
            </div>
        </div>
    </div>
</div>

<div class="kpi-grid">
    <div class="stat animate-fade-up">
        <div class="stat-head"><div><div class="stat-label">Active loans</div></div><div class="stat-icon">📄</div></div>
        <div class="stat-value"><?= (int)$loanStats['active'] ?></div>
        <div class="stat-trend muted"><?= (int)$loanStats['completed'] ?> completed</div>
    </div>

    <div class="stat animate-fade-up delay-1">
        <div class="stat-head"><div><div class="stat-label">Outstanding</div></div><div class="stat-icon">📊</div></div>
        <div class="stat-value"><?= e(money((float)$loanStats['outstanding'])) ?></div>
        <div class="stat-trend muted">Loan balance</div>
    </div>

    <div class="stat animate-fade-up delay-2">
        <div class="stat-head"><div><div class="stat-label">Next due</div></div><div class="stat-icon">⏰</div></div>
        <?php if ($nextDue): ?>
            <div class="stat-value" style="font-size:1.3rem;"><?= e(money((float)$nextDue['total_due'])) ?></div>
            <div class="stat-trend muted"><?= e(date('M j, Y', strtotime($nextDue['due_date']))) ?></div>
        <?php else: ?>
            <div class="stat-value" style="font-size:1.3rem;color:var(--accent);">All clear</div>
            <div class="stat-trend muted">No installments due</div>
        <?php endif; ?>
    </div>

    <div class="stat animate-fade-up delay-3">
        <div class="stat-head"><div><div class="stat-label">Dividends received</div></div><div class="stat-icon">💵</div></div>
        <div class="stat-value" style="color:var(--accent);"><?= e(money($dividendsReceived)) ?></div>
        <div class="stat-trend muted">All-time</div>
    </div>
</div>

<div class="dash-grid">

    <div class="card animate-fade-up delay-4">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
            <div>
                <div class="card-title">Savings trend</div>
                <div class="card-sub">Deposits and withdrawals, last 6 months</div>
            </div>
            <div style="display:flex;gap:14px;font-size:.78rem;">
                <span style="display:flex;align-items:center;gap:6px;">
                    <span style="width:10px;height:10px;background:var(--accent);border-radius:2px;"></span>In
                </span>
                <span style="display:flex;align-items:center;gap:6px;">
                    <span style="width:10px;height:10px;background:var(--danger);border-radius:2px;"></span>Out
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
            <div class="center muted" style="padding:32px;">No activity yet.</div>
        <?php endif; ?>
    </div>

    <div class="card animate-fade-up delay-5">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <div class="card-title">Recent activity</div>
            <a href="savings.php" class="btn btn-ghost btn-sm">View all →</a>
        </div>
        <?php if ($recent): ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($recent as $t): ?>
                    <?php
                        $muted = (int)$t['is_reversed'] === 1 || (int)$t['reversal_of_id'] > 0;
                    ?>
                    <div style="display:flex;justify-content:space-between;padding:10px;border:1px solid var(--border-2);border-radius:10px;<?= $muted ? 'opacity:.55;' : '' ?>">
                        <div>
                            <div style="font-weight:600;font-size:.85rem;"><?= e(ucfirst($t['type'])) ?></div>
                            <div class="muted" style="font-size:.75rem;"><?= e(date('M j, Y', strtotime($t['transaction_date']))) ?></div>
                        </div>
                        <div style="font-weight:700;color:<?= $t['type'] === 'deposit' ? 'var(--accent)' : 'var(--danger)' ?>;<?= $muted ? 'text-decoration:line-through;' : '' ?>">
                            <?= $t['type'] === 'deposit' ? '+' : '−' ?><?= e(money((float)$t['amount'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="center muted" style="padding:32px;">No transactions yet.</div>
        <?php endif; ?>
    </div>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>