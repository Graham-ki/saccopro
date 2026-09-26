<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_member();
$member = current_member();
$memberId = (int)$member['id'];

$loans = $pdo->prepare("
    SELECT * FROM loans WHERE member_id = ?
    ORDER BY created_at DESC
");
$loans->execute([$memberId]);
$loans = $loans->fetchAll();

$stats = [
    'active'      => 0,
    'outstanding' => 0.0,
    'completed'   => 0,
    'total'       => count($loans),
];
foreach ($loans as $l) {
    if ($l['status'] === 'active') {
        $stats['active']++;
        $stats['outstanding'] += (float)$l['balance'];
    }
    if ($l['status'] === 'completed') $stats['completed']++;
}

$pageTitle = 'My Loans';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>My Loans</h1>
        <p>Everything about your borrowing history.</p>
    </div>
    <div class="page-head-actions">
        <a href="request-loan.php" class="btn btn-primary">+ Request loan</a>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:18px;">
    <div class="stat">
        <div class="stat-label">Active loans</div>
        <div class="stat-value"><?= (int)$stats['active'] ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Outstanding</div>
        <div class="stat-value"><?= e(money($stats['outstanding'])) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Completed</div>
        <div class="stat-value"><?= (int)$stats['completed'] ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Total loans</div>
        <div class="stat-value"><?= (int)$stats['total'] ?></div>
    </div>
</div>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Loan</th>
                <th>Issued</th>
                <th style="text-align:right;">Principal</th>
                <th style="text-align:right;">Balance</th>
                <th>Maturity</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($loans as $l): ?>
            <?php
                $pct = (float)$l['total_payable'] > 0
                    ? round((float)$l['amount_paid'] / (float)$l['total_payable'] * 100)
                    : 0;
                $overdue = $l['status'] === 'active' && strtotime($l['maturity_date']) < time();
            ?>
            <tr>
                <td><code style="font-size:.8rem;"><?= e($l['loan_no']) ?></code></td>
                <td><?= e(date('M j, Y', strtotime($l['issue_date']))) ?></td>
                <td style="text-align:right;"><?= e(money((float)$l['principal'])) ?></td>
                <td style="text-align:right;font-weight:600;"><?= e(money((float)$l['balance'])) ?></td>
                <td>
                    <?= e(date('M j, Y', strtotime($l['maturity_date']))) ?>
                    <?php if ($overdue): ?>
                        <div><span class="badge badge-rejected" style="font-size:.65rem;">Overdue</span></div>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-<?= $l['status'] === 'active' ? 'info' : ($l['status'] === 'completed' ? 'approved' : 'rejected') ?>">
                        <?= e($l['status']) ?>
                    </span>
                </td>
                <td style="text-align:right;">
                    <a href="loan-view.php?id=<?= (int)$l['id'] ?>" class="btn btn-outline btn-sm">View</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$loans): ?>
            <tr><td colspan="7" class="center muted" style="padding:48px;">
                <div style="font-size:2rem;margin-bottom:8px;">📄</div>
                <div style="font-weight:600;color:var(--text);">No loans yet</div>
                <div style="font-size:.85rem;margin-bottom:14px;">Apply for your first loan from the button above.</div>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>