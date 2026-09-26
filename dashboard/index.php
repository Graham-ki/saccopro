<?php
declare(strict_types=1);

// DEV ONLY — remove this in production
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// ---- Bootstrap (no output) ----
require_once __DIR__ . '/../includes/auth.php';
$user = require_login();

/* =====================================================================
   DATA LAYER — grouped by domain
   ===================================================================== */

// ---- Members -----------------------------------------------------------
$memberStats = $pdo->query("
    SELECT
        COUNT(*)                                              AS total,
        SUM(status = 'active')                                 AS active,
        SUM(status = 'inactive')                               AS inactive,
        SUM(MONTH(join_date) = MONTH(CURDATE())
            AND YEAR(join_date) = YEAR(CURDATE()))             AS new_this_month
    FROM members
")->fetch();

// Members with no savings activity in 60+ days (churn signal)
$dormantMembers = (int)$pdo->query("
    SELECT COUNT(*) FROM members m
    JOIN savings_accounts sa ON sa.member_id = m.id
    WHERE m.status = 'active'
      AND sa.balance = 0
      AND NOT EXISTS (
          SELECT 1 FROM savings_transactions st
          WHERE st.account_id = sa.id
            AND st.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
      )
")->fetchColumn();

// ---- Savings -----------------------------------------------------------
$savingsStats = $pdo->query("
    SELECT COALESCE(SUM(balance), 0) AS total_balance
    FROM savings_accounts
")->fetch();

$savingsThisMonth = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN type = 'deposit'    AND is_reversed = 0 THEN amount END), 0) AS deposits,
        COALESCE(SUM(CASE WHEN type = 'withdrawal' AND is_reversed = 0 THEN amount END), 0) AS withdrawals,
        COUNT(CASE WHEN is_reversed = 0 AND reversal_of_id IS NULL THEN 1 END)               AS txn_count
    FROM savings_transactions
    WHERE MONTH(transaction_date) = MONTH(CURDATE())
      AND YEAR(transaction_date)  = YEAR(CURDATE())
")->fetch();

$netThisMonth = (float)$savingsThisMonth['deposits'] - (float)$savingsThisMonth['withdrawals'];

$recentSavings = $pdo->query("
    SELECT st.id, st.type, st.amount, st.transaction_date, st.reference,
           st.is_reversed, st.reversal_of_id,
           m.id AS member_id, m.first_name, m.last_name
    FROM savings_transactions st
    JOIN savings_accounts sa ON sa.id = st.account_id
    JOIN members m ON m.id = sa.member_id
    ORDER BY st.created_at DESC, st.id DESC
    LIMIT 6
")->fetchAll();

// ---- Loans -------------------------------------------------------------
$loanStats = $pdo->query("
    SELECT
        SUM(status = 'active')                                         AS active_count,
        SUM(status = 'pending')                                        AS pending_count,
        SUM(status = 'completed')                                      AS completed_count,
        SUM(status = 'defaulted')                                      AS defaulted_count,
        COALESCE(SUM(CASE WHEN status = 'active' THEN balance END), 0) AS outstanding,
        COALESCE(SUM(principal), 0)                                    AS total_disbursed,
        COALESCE(AVG(principal), 0)                                    AS avg_loan_size
    FROM loans
")->fetch();

// Interest collected this month
$interestThisMonth = (float)$pdo->query("
    SELECT COALESCE(SUM(interest_portion), 0)
    FROM loan_repayments
    WHERE is_reversed = 0
      AND MONTH(payment_date) = MONTH(CURDATE())
      AND YEAR(payment_date)  = YEAR(CURDATE())
")->fetchColumn();

// ---- Upcoming / overdue installments -----------------------------------
$upcoming = [];
$overdueStats = ['count' => 0, 'amount_due' => 0];
try {
    $upcoming = $pdo->query("
        SELECT ls.id, ls.installment_no, ls.due_date, ls.total_due, ls.amount_paid, ls.status,
               l.id AS loan_id, l.loan_no,
               m.first_name, m.last_name
        FROM loan_schedules ls
        JOIN loans l ON l.id = ls.loan_id
        JOIN members m ON m.id = l.member_id
        WHERE ls.status IN ('pending','partial','overdue')
          AND l.status = 'active'
        ORDER BY ls.due_date ASC
        LIMIT 5
    ")->fetchAll();

    $overdueStats = $pdo->query("
        SELECT
            COUNT(*)                                        AS count,
            COALESCE(SUM(total_due - amount_paid), 0)        AS amount_due
        FROM loan_schedules
        WHERE status = 'overdue'
           OR (due_date < CURDATE() AND status IN ('pending','partial'))
    ")->fetch();
} catch (Throwable $e) {
    // loan_schedules doesn't exist yet
}

// ---- Render ------------------------------------------------------------
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';

$firstName = explode(' ', trim($user['full_name']))[0];
$overdueCount = (int)$overdueStats['count'];
?>
<style>
/* Hero row: savings card on its own for emphasis */
.kpi-hero-row {
    margin-bottom: 18px;
}
.kpi-hero-row .stat-hero {
    width: 100%;
    grid-column: auto;
    padding: 28px;
}
.stat-hero-value { font-size: 2.8rem; }
.stat-hero-meta { margin-top: 10px; }

/* Split strip inside the hero */
.stat-hero-split {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-top: 22px;
    padding-top: 18px;
    border-top: 1px solid var(--border-2);
}

/* Secondary KPI grid: 4 cards per row */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 24px;
}
.kpi-grid .stat-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: grid; place-items: center;
    background: var(--primary-l);
    color: var(--primary);
    font-size: 1rem;
}

/* Two-column dashboard grid */
.dash-grid {
    display: grid;
    grid-template-columns: 1.6fr 1fr;
    gap: 18px;
}

/* Hero card cosmetics */
.stat-hero {
    background: linear-gradient(135deg, var(--surface) 0%, var(--surface-2) 100%);
    border: 1px solid var(--primary) !important;
    border-radius: var(--radius);
    position: relative;
    overflow: hidden;
    padding: 28px;
}
.stat-hero::after {
    content: '';
    position: absolute;
    top: -40%; right: -20%;
    width: 260px; height: 260px;
    background: radial-gradient(circle, rgba(79,70,229,.18), transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}
.stat-hero-value {
    font-weight: 800;
    letter-spacing: -0.02em;
    line-height: 1.1;
    margin: 8px 0 4px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
.stat-icon-hero {
    width: 56px; height: 56px;
    border-radius: 14px;
    display: grid; place-items: center;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    color: #fff;
    font-size: 1.5rem;
    box-shadow: 0 8px 20px rgba(79,70,229,.35);
}

/* Responsive */
@media (max-width: 1100px) {
    .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .dash-grid { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
    .kpi-grid { grid-template-columns: 1fr; }
    .stat-hero-value { font-size: 2rem; }
    .stat-hero-split { grid-template-columns: 1fr; }
    .stat-hero { padding: 20px; }
}
</style>
<div class="page-head">
    <div>
        <h1>Welcome back, <?= e($firstName) ?> 👋</h1>
        <p>Here's what's happening in your group today.</p>
    </div>
    <div class="page-head-actions">
        <a href="/scms/members/create.php" class="btn btn-outline">+ Add member</a>
        <a href="/scms/savings/deposit.php" class="btn btn-primary">Record deposit</a>
    </div>
</div>

<?php if ($overdueCount > 0): ?>
    <div class="alert alert-error" style="display:flex;align-items:center;gap:12px;justify-content:space-between;flex-wrap:wrap;">
        <div>
            <strong>⚠️ <?= $overdueCount ?> overdue installment<?= $overdueCount === 1 ? '' : 's' ?></strong>
            · <?= e(money((float)$overdueStats['amount_due'])) ?> outstanding
        </div>
        <a href="/scms/loans/index.php?status=active" class="btn btn-sm" style="background:var(--danger);color:#fff;">Review →</a>
    </div>
<?php endif; ?>

<!-- Hero KPI: total savings -->
<div class="kpi-hero-row">
    <div class="stat stat-hero animate-fade-up">
        <div class="stat-head">
            <div>
                <div class="stat-label">Total savings held</div>
                <div class="stat-hero-value"><?= e(money((float)$savingsStats['total_balance'])) ?></div>
                <div class="stat-hero-meta">
                    Across <strong><?= (int)$memberStats['active'] ?></strong> active ·
                    <strong><?= (int)$memberStats['total'] ?></strong> total members
                </div>
            </div>
            <div class="stat-icon stat-icon-hero">💰</div>
        </div>
        <div class="stat-hero-split">
            <div>
                <div class="muted" style="font-size:.72rem;text-transform:uppercase;">This month in</div>
                <div style="font-weight:700;color:var(--accent);">+<?= e(money((float)$savingsThisMonth['deposits'])) ?></div>
            </div>
            <div>
                <div class="muted" style="font-size:.72rem;text-transform:uppercase;">This month out</div>
                <div style="font-weight:700;color:var(--danger);">−<?= e(money((float)$savingsThisMonth['withdrawals'])) ?></div>
            </div>
            <div>
                <div class="muted" style="font-size:.72rem;text-transform:uppercase;">Net flow</div>
                <div style="font-weight:700;color:<?= $netThisMonth >= 0 ? 'var(--accent)' : 'var(--danger)' ?>;">
                    <?= e(money($netThisMonth)) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Secondary KPI grid -->
<div class="kpi-grid">

    <div class="stat animate-fade-up">
        <div class="stat-head">
            <div><div class="stat-label">Active members</div></div>
            <div class="stat-icon">👥</div>
        </div>
        <div class="stat-value"><?= number_format((int)$memberStats['active']) ?></div>
        <div class="stat-trend <?= (int)$memberStats['new_this_month'] > 0 ? 'up' : 'muted' ?>">
            <?= (int)$memberStats['new_this_month'] > 0 ? '▲ ' : '' ?>
            <?= (int)$memberStats['new_this_month'] ?> joined this month
        </div>
    </div>

    <div class="stat animate-fade-up delay-1">
        <div class="stat-head">
            <div><div class="stat-label">Loans outstanding</div></div>
            <div class="stat-icon">📄</div>
        </div>
        <div class="stat-value"><?= e(money((float)$loanStats['outstanding'])) ?></div>
        <div class="stat-trend muted">
            <?= (int)$loanStats['active_count'] ?> active loan<?= (int)$loanStats['active_count'] === 1 ? '' : 's' ?>
        </div>
    </div>

    <div class="stat animate-fade-up delay-2">
        <div class="stat-head">
            <div><div class="stat-label">Interest earned (month)</div></div>
            <div class="stat-icon">💵</div>
        </div>
        <div class="stat-value" style="color:var(--accent);"><?= e(money($interestThisMonth)) ?></div>
        <div class="stat-trend muted">From loan repayments</div>
    </div>

    <div class="stat animate-fade-up delay-3">
        <div class="stat-head">
            <div><div class="stat-label">Dormant accounts</div></div>
            <div class="stat-icon">💤</div>
        </div>
        <div class="stat-value"><?= $dormantMembers ?></div>
        <div class="stat-trend muted">No activity in 60 days</div>
    </div>

    <div class="stat animate-fade-up">
        <div class="stat-head">
            <div><div class="stat-label">Overdue installments</div></div>
            <div class="stat-icon">⚠️</div>
        </div>
        <div class="stat-value" style="color:<?= $overdueCount > 0 ? 'var(--danger)' : 'inherit' ?>;">
            <?= $overdueCount ?>
        </div>
        <div class="stat-trend muted"><?= e(money((float)$overdueStats['amount_due'])) ?> due</div>
    </div>

    <div class="stat animate-fade-up delay-1">
        <div class="stat-head">
            <div><div class="stat-label">Pending loan reviews</div></div>
            <div class="stat-icon">⏰</div>
        </div>
        <div class="stat-value"><?= (int)$loanStats['pending_count'] ?></div>
        <div class="stat-trend muted">Awaiting approval</div>
    </div>

    <div class="stat animate-fade-up delay-2">
        <div class="stat-head">
            <div><div class="stat-label">Total loans issued</div></div>
            <div class="stat-icon">📊</div>
        </div>
        <div class="stat-value"><?= e(money((float)$loanStats['total_disbursed'])) ?></div>
        <div class="stat-trend muted">Avg <?= e(money((float)$loanStats['avg_loan_size'])) ?></div>
    </div>

    <div class="stat animate-fade-up delay-3">
        <div class="stat-head">
            <div><div class="stat-label">Completed loans</div></div>
            <div class="stat-icon">✅</div>
        </div>
        <div class="stat-value"><?= (int)$loanStats['completed_count'] ?></div>
        <div class="stat-trend muted"><?= (int)$loanStats['defaulted_count'] ?> defaulted</div>
    </div>

</div>

<!-- Two-column: recent savings + upcoming dues -->
<div class="dash-grid">

    <div class="card animate-fade-up delay-4">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
            <div>
                <div class="card-title">Recent savings activity</div>
                <div class="card-sub">Latest deposits and withdrawals.</div>
            </div>
            <a href="/scms/savings/history.php" class="btn btn-ghost btn-sm">View all →</a>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Type</th>
                        <th style="text-align:right;">Amount</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentSavings as $r): ?>
                    <?php
                        $isReversed = (int)$r['is_reversed'] === 1;
                        $isReversal = (int)$r['reversal_of_id'] > 0;
                        $muted = $isReversed || $isReversal;
                    ?>
                    <tr style="<?= $muted ? 'opacity:.6;' : '' ?>">
                        <td>
                            <a href="/scms/members/view.php?id=<?= (int)$r['member_id'] ?>" style="font-weight:600;">
                                <?= e($r['first_name'] . ' ' . $r['last_name']) ?>
                            </a>
                        </td>
                        <td>
                            <span class="badge badge-<?= $r['type'] === 'deposit' ? 'approved' : 'rejected' ?>">
                                <?= e(ucfirst($r['type'])) ?>
                            </span>
                        </td>
                        <td style="text-align:right;font-weight:600;color:<?= $r['type'] === 'deposit' ? 'var(--accent)' : 'var(--danger)' ?>;<?= $muted ? 'text-decoration:line-through;' : '' ?>">
                            <?= $r['type'] === 'deposit' ? '+' : '−' ?><?= e(money((float)$r['amount'])) ?>
                        </td>
                        <td class="muted" style="white-space:nowrap;font-size:.82rem;">
                            <?= e(date('M j, Y', strtotime($r['transaction_date']))) ?>
                        </td>
                        <td>
                            <?php if ($isReversed): ?>
                                <span class="badge badge-rejected">Reversed</span>
                            <?php elseif ($isReversal): ?>
                                <span class="badge badge-pending">Reversal</span>
                            <?php else: ?>
                                <span class="badge badge-approved">Posted</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentSavings): ?>
                    <tr><td colspan="5" class="center muted" style="padding:32px;">
                        <div style="font-size:2rem;margin-bottom:8px;">💤</div>
                        <div style="font-weight:600;color:var(--text);margin-bottom:4px;">No savings activity yet</div>
                        <div style="font-size:.85rem;">Record a deposit to get started.</div>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card animate-fade-up delay-5">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <div>
                <div class="card-title">Upcoming dues</div>
                <div class="card-sub">Next 5 installments.</div>
            </div>
            <a href="/scms/loans/index.php" class="btn btn-ghost btn-sm">Loans →</a>
        </div>

        <?php if ($upcoming): ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($upcoming as $u): ?>
                    <?php
                        $outstanding = max(0, (float)$u['total_due'] - (float)$u['amount_paid']);
                        $isOverdue = $u['status'] === 'overdue'
                                  || strtotime($u['due_date']) < strtotime('today');
                        $isToday   = $u['due_date'] === date('Y-m-d');
                        $borderColor = $isOverdue ? 'rgba(239,68,68,.35)' : 'var(--border-2)';
                        $bgColor     = $isOverdue ? 'rgba(239,68,68,.05)' : 'var(--surface)';
                    ?>
                    <a href="/scms/loans/view.php?id=<?= (int)$u['loan_id'] ?>"
                       style="display:block;text-decoration:none;color:inherit;padding:12px;border:1px solid <?= $borderColor ?>;border-radius:10px;background:<?= $bgColor ?>;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                            <div>
                                <div style="font-weight:600;font-size:.9rem;">
                                    <?= e($u['first_name'] . ' ' . $u['last_name']) ?>
                                </div>
                                <div class="muted" style="font-size:.78rem;">
                                    <code><?= e($u['loan_no']) ?></code>
                                    · installment #<?= (int)$u['installment_no'] ?>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-weight:700;"><?= e(money($outstanding)) ?></div>
                                <div style="font-size:.75rem;color:<?= $isOverdue ? 'var(--danger)' : 'var(--text-3)' ?>;">
                                    <?= e(date('M j', strtotime($u['due_date']))) ?>
                                    <?php if ($isOverdue): ?>
                                        · overdue
                                    <?php elseif ($isToday): ?>
                                        · today
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="text-align:center;padding:40px 20px;">
                <div style="font-size:1.8rem;margin-bottom:8px;">✅</div>
                <div style="font-weight:600;">No upcoming dues</div>
                <div class="muted" style="font-size:.85rem;">All installments are up to date.</div>
            </div>
        <?php endif; ?>
    </div>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>