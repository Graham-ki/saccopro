<?php
$pageTitle = 'Loan Details';
require_once __DIR__ . '/../includes/header.php';

$id = (int)($_GET['id'] ?? 0);

// Auto-mark overdue installments (simple, cheap)
$pdo->exec("UPDATE loan_schedules
            SET status = 'overdue'
            WHERE status IN ('pending','partial') AND due_date < CURDATE()");

$stmt = $pdo->prepare("
    SELECT l.*, m.id AS member_id, m.first_name, m.last_name, m.member_no, m.phone,
           u1.full_name AS approved_by_name, u2.full_name AS created_by_name
    FROM loans l
    JOIN members m ON m.id = l.member_id
    LEFT JOIN users u1 ON u1.id = l.approved_by
    LEFT JOIN users u2 ON u2.id = l.created_by
    WHERE l.id = ? LIMIT 1
");
$stmt->execute([$id]);
$l = $stmt->fetch();

if (!$l) {
    flash('error', 'Loan not found.');
    redirect('/scms/loans/index.php');
}

$schedule = $pdo->prepare("SELECT * FROM loan_schedules WHERE loan_id = ? ORDER BY installment_no ASC");
$schedule->execute([$id]);
$schedule = $schedule->fetchAll();

$repayments = $pdo->prepare("
    SELECT r.*, u.full_name AS recorded_by_name
    FROM loan_repayments r
    LEFT JOIN users u ON u.id = r.recorded_by
    WHERE r.loan_id = ?
    ORDER BY r.payment_date DESC, r.id DESC
");
$repayments->execute([$id]);
$repayments = $repayments->fetchAll();

$principal = (float)$l['principal'];
$paid = (float)$l['amount_paid'];
$total = (float)$l['total_payable'];
$balance = (float)$l['balance'];
$pct = $total > 0 ? round($paid / $total * 100) : 0;

$principalPaid = 0.0;
$interestPaid = 0.0;
foreach ($repayments as $r) {
    $principalPaid += (float)$r['principal_portion'];
    $interestPaid  += (float)$r['interest_portion'];
}

$nextDue = null;
foreach ($schedule as $s) {
    if (in_array($s['status'], ['pending','partial','overdue'], true)) { $nextDue = $s; break; }
}
$isOverdue = $l['status'] === 'active' && $nextDue && $nextDue['status'] === 'overdue';
  $topupStmt = $pdo->prepare("
        SELECT * FROM loan_topups
        WHERE loan_id = ?
        ORDER BY topup_date DESC, id DESC
    ");
    $topupStmt->execute([$id]);
    $topupList = $topupStmt->fetchAll();
    $topupTotal = array_sum(array_map(fn($t) => (float)$t['amount'], $topupList));

     $guarStmt = $pdo->prepare("
        SELECT g.*, m.first_name, m.last_name, m.member_no
        FROM loan_guarantors g
        JOIN members m ON m.id = g.member_id
        WHERE g.loan_id = ?
        ORDER BY g.status ASC, g.created_at DESC
    ");
    $guarStmt->execute([$id]);
    $guarList = $guarStmt->fetchAll();
    $guarActive = array_filter($guarList, fn($g) => $g['status'] === 'active');
    $guarTotal = array_sum(array_map(fn($g) => (float)$g['amount_guaranteed'], $guarActive));
?>

<div class="page-head">
    <div>
        <h1>Loan <code><?= e($l['loan_no']) ?></code></h1>
        <p>
            <a href="/scms/members/view.php?id=<?= (int)$l['member_id'] ?>">
                <?= e($l['first_name'] . ' ' . $l['last_name']) ?>
            </a>
            · <code><?= e($l['member_no']) ?></code>
            · <span class="badge badge-<?= $l['status'] === 'active' ? 'info' : ($l['status'] === 'completed' ? 'approved' : 'rejected') ?>"><?= e($l['status']) ?></span>
            <?php if ($isOverdue): ?>
                <span class="badge badge-rejected">Overdue</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Loans</a>
       <?php if ($l['status'] === 'active'): ?>
            <a href="guarantors.php?loan_id=<?= $id ?>" class="btn btn-outline">👥 Guarantors</a>
            <a href="topup.php?loan_id=<?= $id ?>" class="btn btn-outline">+ Top up</a>
            <a href="repay.php?loan_id=<?= $id ?>" class="btn btn-primary">+ Record repayment</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><?= e($msg) ?></div>
<?php endif; ?>

<!-- KPIs -->
<div class="stats-grid">
    <div class="stat animate-fade-up">
        <div class="stat-head"><div><div class="stat-label">Principal</div></div><div class="stat-icon">💵</div></div>
        <div class="stat-value"><?= e(money($principal)) ?></div>
        <div class="stat-trend muted"><?= e(rtrim(rtrim(number_format((float)$l['interest_rate'], 2), '0'), '.')) ?>%/mo · <?= e($l['interest_method']) ?></div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-head"><div><div class="stat-label">Total payable</div></div><div class="stat-icon">📄</div></div>
        <div class="stat-value"><?= e(money($total)) ?></div>
        <div class="stat-trend muted">Interest <?= e(money($l['total_interest'])) ?></div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-head"><div><div class="stat-label">Balance</div></div><div class="stat-icon">📊</div></div>
        <div class="stat-value" style="color:<?= $balance > 0 ? 'var(--warning)' : 'var(--accent)' ?>;"><?= e(money($balance)) ?></div>
        <div class="stat-trend muted"><?= $pct ?>% paid</div>
    </div>
    <div class="stat animate-fade-up delay-3">
        <div class="stat-head"><div><div class="stat-label">Next due</div></div><div class="stat-icon">⏰</div></div>
        <?php if ($nextDue): ?>
            <div class="stat-value" style="font-size:1.3rem;"><?= e(money($nextDue['total_due'])) ?></div>
            <div class="stat-trend <?= $nextDue['status'] === 'overdue' ? 'down' : 'muted' ?>">
                <?= e(date('M j, Y', strtotime($nextDue['due_date']))) ?>
            </div>
        <?php else: ?>
            <div class="stat-value" style="font-size:1.3rem;color:var(--accent);">Paid off</div>
            <div class="stat-trend muted">No further installments</div>
        <?php endif; ?>
    </div>
</div>

<!-- Progress bar -->
<div class="card animate-fade-up" style="margin-top:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
        <div>
            <div class="card-title">Repayment progress</div>
            <div class="card-sub"><?= e(money($paid)) ?> of <?= e(money($total)) ?> repaid</div>
        </div>
        <div style="font-weight:800;font-size:1.4rem;"><?= $pct ?>%</div>
    </div>
    <div style="height:10px;background:var(--border-2);border-radius:99px;overflow:hidden;">
        <div style="width:<?= $pct ?>%;height:100%;background:linear-gradient(90deg,var(--primary),var(--accent));transition:width .6s;"></div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-top:18px;">
        <div><div class="muted" style="font-size:.75rem;text-transform:uppercase;">Principal paid</div><div style="font-weight:700;"><?= e(money($principalPaid)) ?></div></div>
        <div><div class="muted" style="font-size:.75rem;text-transform:uppercase;">Interest paid</div><div style="font-weight:700;"><?= e(money($interestPaid)) ?></div></div>
        <div><div class="muted" style="font-size:.75rem;text-transform:uppercase;">Installments cleared</div><div style="font-weight:700;"><?= count(array_filter($schedule, fn($s) => $s['status'] === 'paid')) ?> / <?= count($schedule) ?></div></div>
        <div><div class="muted" style="font-size:.75rem;text-transform:uppercase;">Issue date</div><div style="font-weight:700;"><?= e(date('M j, Y', strtotime($l['issue_date']))) ?></div></div>
        <div><div class="muted" style="font-size:.75rem;text-transform:uppercase;">Maturity</div><div style="font-weight:700;"><?= e(date('M j, Y', strtotime($l['maturity_date']))) ?></div></div>
    </div>
    <?php if ($l['purpose']): ?>
        <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border-2);">
            <div class="muted" style="font-size:.75rem;text-transform:uppercase;">Purpose</div>
            <div style="font-size:.9rem;"><?= e($l['purpose']) ?></div>
        </div>
    <?php endif; ?>
</div>

<!-- Schedule + Repayments -->
<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:18px;margin-top:18px;">
    <div class="card animate-fade-up">
        <h3 class="card-title" style="margin-bottom:14px;">Amortization schedule</h3>
        <div class="table-wrap" style="max-height:520px;overflow-y:auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Due date</th>
                        <th style="text-align:right;">Principal</th>
                        <th style="text-align:right;">Interest</th>
                        <th style="text-align:right;">Total</th>
                        <th style="text-align:right;">Paid</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($schedule as $s): ?>
                    <?php
                        $remaining = (float)$s['total_due'] - (float)$s['amount_paid'];
                    ?>
                    <tr style="<?= $s['status'] === 'overdue' ? 'background:rgba(239,68,68,.06);' : '' ?>">
                        <td><?= (int)$s['installment_no'] ?></td>
                        <td style="white-space:nowrap;"><?= e(date('M j, Y', strtotime($s['due_date']))) ?></td>
                        <td style="text-align:right;"><?= e(money($s['principal_due'])) ?></td>
                        <td style="text-align:right;"><?= e(money($s['interest_due'])) ?></td>
                        <td style="text-align:right;font-weight:600;"><?= e(money($s['total_due'])) ?></td>
                        <td style="text-align:right;">
                            <?= e(money($s['amount_paid'])) ?>
                            <?php if ($remaining > 0.01 && $s['amount_paid'] > 0): ?>
                                <div class="muted" style="font-size:.72rem;"><?= e(money($remaining)) ?> left</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= e($s['status']) === 'paid' ? 'approved' : ($s['status'] === 'overdue' ? 'rejected' : ($s['status'] === 'partial' ? 'pending' : 'info')) ?>">
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
        <h3 class="card-title" style="margin-bottom:14px;">Repayment history</h3>
        <?php if ($repayments): ?>
            <div style="display:flex;flex-direction:column;gap:10px;max-height:520px;overflow-y:auto;">
                <?php foreach ($repayments as $r): ?>
                    <div style="padding:12px;border:1px solid var(--border-2);border-radius:10px;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                            <div>
                                <div style="font-weight:700;"><?= e(money($r['amount'])) ?></div>
                                <div class="muted" style="font-size:.78rem;"><code><?= e($r['reference']) ?></code></div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:.78rem;color:var(--text-3);"><?= e(date('M j, Y', strtotime($r['payment_date']))) ?></div>
                                <div style="font-size:.72rem;color:var(--text-3);"><?= e(ucfirst(str_replace('_',' ',$r['method']))) ?></div>
                            </div>
                        </div>
                        <div style="display:flex;gap:14px;margin-top:8px;font-size:.78rem;color:var(--text-2);">
                            <span>P: <?= e(money($r['principal_portion'])) ?></span>
                            <span>I: <?= e(money($r['interest_portion'])) ?></span>
                        </div>
                        <?php if ($r['notes']): ?>
                            <div class="muted" style="font-size:.78rem;margin-top:6px;"><?= e($r['notes']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="text-align:center;padding:40px 20px;">
                <div style="font-size:1.8rem;margin-bottom:8px;">💳</div>
                <div style="font-weight:600;margin-bottom:4px;">No repayments yet</div>
                <div class="muted" style="font-size:.85rem;margin-bottom:14px;">Record the first payment to begin.</div>
                <?php if ($l['status'] === 'active'): ?>
                    <a href="repay.php?loan_id=<?= $id ?>" class="btn btn-primary btn-sm">+ Record repayment</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($topupList): ?>
    
            <div class="card animate-fade-up" style="margin-top:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
        <div>
            <div class="card-title">Guarantors</div>
            <div class="card-sub">
                <?= count($guarActive) ?> active · <?= e(money($guarTotal)) ?> guaranteed
            </div>
        </div>
        <a href="guarantors.php?loan_id=<?= $id ?>" class="btn btn-ghost btn-sm">Manage →</a>
    </div>

    <?php if ($guarActive): ?>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <?php foreach ($guarActive as $g): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid var(--border-2);border-radius:10px;">
                    <div>
                        <a href="/scms/members/view.php?id=<?= (int)$g['member_id'] ?>" style="font-weight:600;">
                            <?= e($g['first_name'] . ' ' . $g['last_name']) ?>
                        </a>
                        <div class="muted" style="font-size:.76rem;"><code><?= e($g['member_no']) ?></code></div>
                    </div>
                    <div style="font-weight:700;"><?= e(money((float)$g['amount_guaranteed'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="center muted" style="padding:24px;">
            No active guarantors.
            <?php if ($l['status'] === 'active'): ?>
                <a href="guarantors.php?loan_id=<?= $id ?>" class="btn btn-outline btn-sm" style="margin-top:10px;">Add guarantor</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
            <div class="card animate-fade-up delay-2" style="margin-top:18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
            <div>
                <div class="card-title">Top-ups</div>
                <div class="card-sub">
                    <?= count($topupList) ?> top-up<?= count($topupList) === 1 ? '' : 's' ?> ·
                    Total added: <?= e(money($topupTotal)) ?>
                </div>
            </div>
            <?php if ($l['status'] === 'active'): ?>
                <a href="topup.php?loan_id=<?= $id ?>" class="btn btn-outline btn-sm">+ Add top-up</a>
            <?php endif; ?>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th style="text-align:right;">Amount</th>
                        <th style="text-align:right;">New principal</th>
                        <th style="text-align:right;">New balance</th>
                        <th>Method</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($topupList as $t): ?>
                    <tr>
                        <td><?= e(date('M j, Y', strtotime($t['topup_date']))) ?></td>
                        <td style="text-align:right;font-weight:700;color:var(--accent);">+<?= e(money((float)$t['amount'])) ?></td>
                        <td style="text-align:right;"><?= e(money((float)$t['new_principal'])) ?></td>
                        <td style="text-align:right;"><?= e(money((float)$t['new_balance'])) ?></td>
                        <td><span class="badge badge-info"><?= e(ucfirst(str_replace('_',' ', $t['method']))) ?></span></td>
                        <td class="muted" style="font-size:.82rem;"><?= e($t['notes'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
    </div>
</div>

<style>
@media (max-width:900px) {
    div[style*="grid-template-columns:1.4fr 1fr"] { grid-template-columns:1fr !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>