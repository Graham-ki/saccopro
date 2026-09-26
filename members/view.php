<?php
declare(strict_types=1);

// ---- Bootstrap (no output) ----
require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/guarantors.php';
$id = (int)($_GET['id'] ?? 0);

// ---- Handle POST before any HTML ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_status') {
        $pdo->prepare("UPDATE members SET status = IF(status='active','inactive','active') WHERE id = ?")
            ->execute([$id]);
        flash('success', 'Member status updated.');
        redirect('/scms/members/view.php?id=' . $id);   // exits — no output yet, safe
    }
    audit_log($pdo, (int)$user['id'], 'member.toggle_status', 'member', $id, 'Toggled active/inactive');
}

// ---- Fetch member ----
$stmt = $pdo->prepare("
    SELECT m.*, sa.id AS account_id, sa.account_no, sa.balance, u.full_name AS created_by_name
    FROM members m
    LEFT JOIN savings_accounts sa ON sa.member_id = m.id
    LEFT JOIN users u ON u.id = m.created_by
    WHERE m.id = ? LIMIT 1
");
$stmt->execute([$id]);
$m = $stmt->fetch();

if (!$m) {
    flash('error', 'Member not found.');
    redirect('/scms/members/index.php');
}

$accountId = (int)($m['account_id'] ?? 0);
// Guarantees given by this member
$myGuarantees        = member_active_guarantees($pdo, $id);
$myGuaranteeTotal    = member_guarantee_total($pdo, $id);
$mySavings           = (float)($m['balance'] ?? 0);
$guaranteeCapacityLeft = max(0, $mySavings - $myGuaranteeTotal);
// ---- Related stats ----
$loans = $pdo->prepare("
    SELECT COUNT(*) AS total,
           SUM(status='active') AS active,
           COALESCE(SUM(balance),0) AS outstanding
    FROM loans WHERE member_id = ?
");
$loans->execute([$id]);
$loanStats = $loans->fetch();

// ---- Recent savings activity ----
$recentTxns = [];
if ($accountId) {
    $recent = $pdo->prepare("
        SELECT st.*
        FROM savings_transactions st
        WHERE st.account_id = ?
        ORDER BY st.transaction_date DESC, st.id DESC
        LIMIT 5
    ");
    $recent->execute([$accountId]);
    $recentTxns = $recent->fetchAll();
}

// ---- Recent loans ----
$recentLoans = $pdo->prepare("
    SELECT id, loan_no, principal, balance, status, issue_date, maturity_date
    FROM loans WHERE member_id = ?
    ORDER BY created_at DESC LIMIT 5
");
$recentLoans->execute([$id]);
$recentLoans = $recentLoans->fetchAll();

$initial = strtoupper(substr($m['first_name'], 0, 1) . substr($m['last_name'], 0, 1));

// ---- Render ----
$pageTitle = 'Member Details';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div style="display:flex;align-items:center;gap:16px;">
        <div class="avatar" style="width:64px;height:64px;font-size:1.4rem;border-radius:16px;"><?= e($initial) ?></div>
        <div>
            <h1 style="margin-bottom:2px;"><?= e($m['first_name'] . ' ' . $m['last_name']) ?></h1>
            <p style="margin:0;">
                <code><?= e($m['member_no']) ?></code>
                &nbsp;·&nbsp;
                <span class="badge badge-<?= e($m['status']) ?>"><?= e($m['status']) ?></span>
                &nbsp;·&nbsp; joined <?= e(date('M j, Y', strtotime($m['join_date']))) ?>
            </p>
        </div>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← All members</a>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-outline">Edit</a>
        <a href="/scms/savings/deposit.php?member_id=<?= $id ?>" class="btn btn-primary">+ Deposit</a>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><?= e($msg) ?></div>
<?php endif; ?>

<!-- KPI strip -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
    <div class="stat animate-fade-up">
        <div class="stat-head"><div><div class="stat-label">Savings balance</div></div><div class="stat-icon">💰</div></div>
        <div class="stat-value"><?= e(money($m['balance'] ?? 0)) ?></div>
        <div class="stat-trend muted">Account <?= e($m['account_no'] ?: '—') ?></div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-head"><div><div class="stat-label">Active loans</div></div><div class="stat-icon">📄</div></div>
        <div class="stat-value"><?= (int)$loanStats['active'] ?></div>
        <div class="stat-trend muted"><?= (int)$loanStats['total'] ?> total</div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-head"><div><div class="stat-label">Outstanding</div></div><div class="stat-icon">📊</div></div>
        <div class="stat-value"><?= e(money($loanStats['outstanding'])) ?></div>
        <div class="stat-trend muted">Loan balance</div>
    </div>
</div>

<!-- Details -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-top:18px;">
    <div class="card animate-fade-up">
        <h3 class="card-title" style="margin-bottom:16px;">Member details</h3>
        <dl style="display:grid;grid-template-columns:1fr 1fr;gap:18px 24px;font-size:.9rem;">
            <div><dt class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Phone</dt><dd style="margin:4px 0 0;font-weight:600;"><?= e($m['phone'] ?: '—') ?></dd></div>
            <div><dt class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Email</dt><dd style="margin:4px 0 0;font-weight:600;"><?= e($m['email'] ?: '—') ?></dd></div>
            <div><dt class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Gender</dt><dd style="margin:4px 0 0;font-weight:600;"><?= e($m['gender'] ? ucfirst($m['gender']) : '—') ?></dd></div>
            <div><dt class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Date of birth</dt><dd style="margin:4px 0 0;font-weight:600;"><?= $m['dob'] ? e(date('M j, Y', strtotime($m['dob']))) : '—' ?></dd></div>
            <div><dt class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">ID number</dt><dd style="margin:4px 0 0;font-weight:600;"><?= e($m['id_number'] ?: '—') ?></dd></div>
            <div><dt class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Address</dt><dd style="margin:4px 0 0;font-weight:600;"><?= e($m['address'] ?: '—') ?></dd></div>
        </dl>

        <?php if (!empty($m['notes'])): ?>
            <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border-2);">
                <div class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;">Notes</div>
                <div style="font-size:.9rem;"><?= nl2br(e($m['notes'])) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card animate-fade-up delay-1">
        <h3 class="card-title" style="margin-bottom:16px;">Meta</h3>
        <dl style="display:flex;flex-direction:column;gap:14px;font-size:.88rem;">
            <div><dt class="muted" style="font-size:.78rem;text-transform:uppercase;">Created by</dt><dd style="margin:4px 0 0;font-weight:600;"><?= e($m['created_by_name'] ?: 'System') ?></dd></div>
            <div><dt class="muted" style="font-size:.78rem;text-transform:uppercase;">Created at</dt><dd style="margin:4px 0 0;font-weight:600;"><?= e(date('M j, Y g:ia', strtotime($m['created_at']))) ?></dd></div>
            <div><dt class="muted" style="font-size:.78rem;text-transform:uppercase;">Last updated</dt><dd style="margin:4px 0 0;font-weight:600;"><?= e(date('M j, Y g:ia', strtotime($m['updated_at']))) ?></dd></div>
        </dl>

        <div style="margin-top:24px;padding-top:20px;border-top:1px solid var(--border-2);display:flex;flex-direction:column;gap:10px;">
            <form method="post" onsubmit="return confirm('Toggle this member\'s status?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle_status">
                <button class="btn btn-outline btn-block btn-sm">
                    <?= $m['status'] === 'active' ? 'Mark as inactive' : 'Reactivate member' ?>
                </button>
            </form>
        </div>
    </div>
</div>
<?php if ($myGuarantees || $myGuaranteeTotal > 0): ?>
    <div class="card animate-fade-up delay-3" style="margin-top:18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
            <div>
                <div class="card-title">Guarantees given</div>
                <div class="card-sub">
                    Total guaranteed: <strong><?= e(money($myGuaranteeTotal)) ?></strong>
                    · Capacity left: <strong><?= e(money($guaranteeCapacityLeft)) ?></strong>
                </div>
            </div>
        </div>

        <?php if ($myGuarantees): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Loan</th>
                            <th>Borrower</th>
                            <th style="text-align:right;">Guaranteed</th>
                            <th style="text-align:right;">Loan balance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($myGuarantees as $g): ?>
                        <tr>
                            <td><a href="/scms/loans/view.php?id=<?= (int)$g['loan_id'] ?>"><code><?= e($g['loan_no']) ?></code></a></td>
                            <td>
                                <a href="/scms/members/view.php?id=<?= (int)$g['borrower_id'] ?>">
                                    <?= e($g['first_name'] . ' ' . $g['last_name']) ?>
                                </a>
                            </td>
                            <td style="text-align:right;font-weight:700;"><?= e(money((float)$g['amount_guaranteed'])) ?></td>
                            <td style="text-align:right;"><?= e(money((float)$g['loan_balance'])) ?></td>
                            <td>
                                <span class="badge badge-<?= $g['loan_status'] === 'active' ? 'info' : ($g['loan_status'] === 'completed' ? 'approved' : 'rejected') ?>">
                                    <?= e($g['loan_status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="muted" style="font-size:.9rem;">No active guarantees.</div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<!-- Recent savings activity -->
<?php if ($recentTxns): ?>
    <div class="card animate-fade-up delay-2" style="margin-top:18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
            <div>
                <div class="card-title">Recent savings activity</div>
                <div class="card-sub">Last <?= count($recentTxns) ?> transaction<?= count($recentTxns) === 1 ? '' : 's' ?> on this member's account.</div>
            </div>
            <?php if ($accountId): ?>
                <a href="/scms/savings/statement.php?account_id=<?= $accountId ?>" class="btn btn-ghost btn-sm">
                    Full statement →
                </a>
            <?php endif; ?>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Type</th>
                        <th style="text-align:right;">Amount</th>
                        <th style="text-align:right;">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentTxns as $t): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?= e(date('M j, Y', strtotime($t['transaction_date']))) ?></td>
                            <td><code style="font-size:.78rem;"><?= e($t['reference']) ?></code></td>
                            <td>
                                <span class="badge badge-<?= $t['type'] === 'deposit' ? 'approved' : 'rejected' ?>">
                                    <?= e(ucfirst($t['type'])) ?>
                                </span>
                            </td>
                            <td style="text-align:right;font-weight:600;color:<?= $t['type'] === 'deposit' ? 'var(--accent)' : 'var(--danger)' ?>;">
                                <?= $t['type'] === 'deposit' ? '+' : '−' ?><?= e(money($t['amount'])) ?>
                            </td>
                            <td style="text-align:right;"><?= e(money($t['balance_after'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($accountId): ?>
    <div class="card animate-fade-up delay-2" style="margin-top:18px;text-align:center;padding:40px 24px;">
        <div style="font-size:2rem;margin-bottom:8px;">💤</div>
        <div style="font-weight:600;margin-bottom:4px;">No savings activity yet</div>
        <div class="muted" style="font-size:.88rem;margin-bottom:16px;">
            This member has a savings account but no transactions recorded.
        </div>
        <a href="/scms/savings/deposit.php?account_id=<?= $accountId ?>" class="btn btn-primary btn-sm">
            + Record first deposit
        </a>
    </div>
<?php endif; ?>

<!-- Recent loans -->
<?php if ($recentLoans): ?>
    <div class="card animate-fade-up delay-3" style="margin-top:18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
            <div>
                <div class="card-title">Loans</div>
                <div class="card-sub">Last <?= count($recentLoans) ?> loan<?= count($recentLoans) === 1 ? '' : 's' ?> for this member.</div>
            </div>
            <a href="/scms/loans/create.php?member_id=<?= $id ?>" class="btn btn-outline btn-sm">+ Issue loan</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Loan</th>
                        <th>Issued</th>
                        <th style="text-align:right;">Principal</th>
                        <th style="text-align:right;">Balance</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLoans as $ln): ?>
                        <tr>
                            <td><code style="font-size:.8rem;"><?= e($ln['loan_no']) ?></code></td>
                            <td><?= e(date('M j, Y', strtotime($ln['issue_date']))) ?></td>
                            <td style="text-align:right;"><?= e(money($ln['principal'])) ?></td>
                            <td style="text-align:right;font-weight:600;"><?= e(money($ln['balance'])) ?></td>
                            <td>
                                <span class="badge badge-<?= $ln['status'] === 'active' ? 'info' : ($ln['status'] === 'completed' ? 'approved' : 'rejected') ?>">
                                    <?= e($ln['status']) ?>
                                </span>
                            </td>
                            <td style="text-align:right;">
                                <a href="/scms/loans/view.php?id=<?= (int)$ln['id'] ?>" class="btn btn-ghost btn-sm">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<style>
@media (max-width:900px) {
    div[style*="grid-template-columns:2fr 1fr"] { grid-template-columns:1fr !important; }
    dl[style*="grid-template-columns:1fr 1fr"] { grid-template-columns:1fr !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>