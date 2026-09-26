<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_member();
$member = current_member();
$memberId = (int)$member['id'];

$loanId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT * FROM loans WHERE id = ? AND member_id = ? LIMIT 1
");
$stmt->execute([$loanId, $memberId]);
$loan = $stmt->fetch();

if (!$loan) {
    flash('error', 'Loan not found.');
    redirect('/scms/portal/loans.php');
}

$schedule = $pdo->prepare("SELECT * FROM loan_schedules WHERE loan_id = ? ORDER BY installment_no ASC");
$schedule->execute([$loanId]);
$schedule = $schedule->fetchAll();

$repayments = $pdo->prepare("
    SELECT * FROM loan_repayments WHERE loan_id = ? ORDER BY payment_date DESC, id DESC
");
$repayments->execute([$loanId]);
$repayments = $repayments->fetchAll();

$topups = [];
try {
    $tp = $pdo->prepare("SELECT * FROM loan_topups WHERE loan_id = ? ORDER BY topup_date DESC, id DESC");
    $tp->execute([$loanId]);
    $topups = $tp->fetchAll();
} catch (Throwable $e) { /* table may not exist */ }

$pct = (float)$loan['total_payable'] > 0
    ? round((float)$loan['amount_paid'] / (float)$loan['total_payable'] * 100)
    : 0;

$principalPaid = 0.0;
$interestPaid = 0.0;
foreach ($repayments as $r) {
    if ((int)$r['is_reversed'] === 1) continue;
    $principalPaid += (float)$r['principal_portion'];
    $interestPaid  += (float)$r['interest_portion'];
}

$pageTitle = 'Loan ' . $loan['loan_no'];
require_once __DIR__ . '/../includes/header.php';
?>

<style>
@media (max-width:900px) {
    div[style*="grid-template-columns:1.5fr 1fr"] { grid-template-columns:1fr !important; }
}
</style>
<div class="page-head">
    <div>
        <h1>Loan <code><?= e($loan['loan_no']) ?></code></h1>
        <p>
            Issued <?= e(date('M j, Y', strtotime($loan['issue_date']))) ?>
            · <span class="badge badge-<?= $loan['status'] === 'active' ? 'info' : ($loan['status'] === 'completed' ? 'approved' : 'rejected') ?>"><?= e($loan['status']) ?></span>
        </p>
    </div>
    <div class="page-head-actions">
        <a href="loans.php" class="btn btn-ghost">← My Loans</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat animate-fade-up">
        <div class="stat-label">Principal</div>
        <div class="stat-value"><?= e(money((float)$loan['principal'])) ?></div>
        <div class="stat-trend muted"><?= e(number_format((float)$loan['interest_rate'], 2)) ?>%/mo · <?= e($loan['interest_method']) ?></div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-label">Total payable</div>
        <div class="stat-value"><?= e(money((float)$loan['total_payable'])) ?></div>
        <div class="stat-trend muted">Interest <?= e(money((float)$loan['total_interest'])) ?></div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-label">Balance</div>
        <div class="stat-value" style="color:<?= (float)$loan['balance'] > 0 ? 'var(--warning)' : 'var(--accent)' ?>;"><?= e(money((float)$loan['balance'])) ?></div>
        <div class="stat-trend muted"><?= $pct ?>% paid</div>
    </div>
    <div class="stat animate-fade-up delay-3">
        <div class="stat-label">Maturity</div>
        <div class="stat-value" style="font-size:1.3rem;"><?= e(date('M j, Y', strtotime($loan['maturity_date']))) ?></div>
    </div>
</div>

<div class="card animate-fade-up" style="margin-top:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <div>
            <div class="card-title">Repayment progress</div>
            <div class="card-sub"><?= e(money((float)$loan['amount_paid'])) ?> of <?= e(money((float)$loan['total_payable'])) ?></div>
        </div>
        <div style="font-weight:800;font-size:1.4rem;"><?= $pct ?>%</div>
    </div>
    <div style="height:10px;background:var(--border-2);border-radius:99px;overflow:hidden;">
        <div style="width:<?= $pct ?>%;height:100%;background:linear-gradient(90deg,var(--primary),var(--accent));"></div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-top:18px;">
        <div><div class="muted" style="font-size:.75rem;text-transform:uppercase;">Principal paid</div><div style="font-weight:700;"><?= e(money($principalPaid)) ?></div></div>
        <div><div class="muted" style="font-size:.75rem;text-transform:uppercase;">Interest paid</div><div style="font-weight:700;"><?= e(money($interestPaid)) ?></div></div>
        <div><div class="muted" style="font-size:.75rem;text-transform:uppercase;">Installments cleared</div><div style="font-weight:700;"><?= count(array_filter($schedule, fn($s) => $s['status'] === 'paid')) ?> / <?= count($schedule) ?></div></div>
        <div><div class="muted" style="font-size:.75rem;text-transform:uppercase;">Monthly installment</div><div style="font-weight:700;"><?= e(money((float)$loan['monthly_installment'])) ?></div></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1.5fr 1fr;gap:18px;margin-top:18px;">
    <div class="card animate-fade-up">
        <h3 class="card-title" style="margin-bottom:14px;">Amortization schedule</h3>
        <div class="table-wrap" style="max-height:520px;overflow-y:auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th><th>Due</th>
                        <th style="text-align:right;">Principal</th>
                        <th style="text-align:right;">Interest</th>
                        <th style="text-align:right;">Total</th>
                        <th style="text-align:right;">Paid</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($schedule as $s): ?>
                    <tr style="<?= $s['status'] === 'overdue' ? 'background:rgba(239,68,68,.06);' : '' ?>">
                        <td><?= (int)$s['installment_no'] ?></td>
                        <td style="white-space:nowrap;"><?= e(date('M j, Y', strtotime($s['due_date']))) ?></td>
                        <td style="text-align:right;"><?= e(money((float)$s['principal_due'])) ?></td>
                        <td style="text-align:right;"><?= e(money((float)$s['interest_due'])) ?></td>
                        <td style="text-align:right;font-weight:600;"><?= e(money((float)$s['total_due'])) ?></td>
                        <td style="text-align:right;"><?= e(money((float)$s['amount_paid'])) ?></td>
                        <td>
                            <span class="badge badge-<?= $s['status'] === 'paid' ? 'approved' : ($s['status'] === 'overdue' ? 'rejected' : ($s['status'] === 'partial' ? 'pending' : 'info')) ?>">
                                <?= e($s['status']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card animate-fade-up delay-1">
        <h3 class="card-title" style="margin-bottom:14px;">Repayments</h3>
        <?php if ($repayments): ?>
            <div style="display:flex;flex-direction:column;gap:10px;max-height:520px;overflow-y:auto;">
                <?php foreach ($repayments as $r): ?>
                    <div style="padding:12px;border:1px solid var(--border-2);border-radius:10px;<?= (int)$r['is_reversed'] ? 'opacity:.55;' : '' ?>">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                            <div>
                                <div style="font-weight:700;<?= (int)$r['is_reversed'] ? 'text-decoration:line-through;' : '' ?>"><?= e(money((float)$r['amount'])) ?></div>
                                <div class="muted" style="font-size:.78rem;"><code><?= e($r['reference']) ?></code></div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:.78rem;color:var(--text-3);"><?= e(date('M j, Y', strtotime($r['payment_date']))) ?></div>
                                <div style="font-size:.72rem;color:var(--text-3);"><?= e(ucfirst(str_replace('_',' ', $r['method']))) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="center muted" style="padding:32px;">No repayments yet.</div>
        <?php endif; ?>
    </div>
</div>

<?php if ($topups): ?>
    <div class="card animate-fade-up delay-2" style="margin-top:18px;">
        <h3 class="card-title" style="margin-bottom:14px;">Top-ups</h3>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th style="text-align:right;">Amount</th>
                        <th style="text-align:right;">New principal</th>
                        <th style="text-align:right;">New balance</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($topups as $t): ?>
                    <tr>
                        <td><?= e(date('M j, Y', strtotime($t['topup_date']))) ?></td>
                        <td style="text-align:right;font-weight:700;color:var(--accent);">+<?= e(money((float)$t['amount'])) ?></td>
                        <td style="text-align:right;"><?= e(money((float)$t['new_principal'])) ?></td>
                        <td style="text-align:right;"><?= e(money((float)$t['new_balance'])) ?></td>
                        <td class="muted" style="font-size:.82rem;"><?= e($t['notes'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>