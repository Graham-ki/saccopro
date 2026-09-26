<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_member();
$member = current_member();
$memberId = (int)$member['id'];
require_once __DIR__ . '/../includes/requests.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    if (($_POST['action'] ?? '') === 'cancel') {
        try {
            cancel_member_request($pdo, (int)$_POST['id'], $memberId);
            flash('success', 'Request cancelled.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/scms/portal/requests.php');
    }
}

$requests = $pdo->prepare("
    SELECT r.*, u.full_name AS decided_by_name
    FROM member_requests r
    LEFT JOIN users u ON u.id = r.decided_by
    WHERE r.member_id = ?
    ORDER BY r.created_at DESC
");
$requests->execute([$memberId]);
$requests = $requests->fetchAll();

$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0];
foreach ($requests as $r) $counts[$r['status']]++;

$pageTitle = 'My Requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>My Requests</h1>
        <p>Everything you've submitted to the office.</p>
    </div>
    <div class="page-head-actions">
        <a href="request-deposit.php" class="btn btn-outline">+ Deposit</a>
        <a href="request-loan.php" class="btn btn-primary">+ Loan</a>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><?= e($msg) ?></div>
<?php endif; ?>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:18px;">
    <div class="stat">
        <div class="stat-label">Pending</div>
        <div class="stat-value" style="color:<?= $counts['pending'] > 0 ? 'var(--warning)' : 'inherit' ?>;"><?= $counts['pending'] ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Approved</div>
        <div class="stat-value"><?= $counts['approved'] ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Rejected</div>
        <div class="stat-value"><?= $counts['rejected'] ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Cancelled</div>
        <div class="stat-value"><?= $counts['cancelled'] ?></div>
    </div>
</div>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>When</th><th>Type</th>
                <th style="text-align:right;">Amount / Qty</th>
                <th>Status</th><th>Decision</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <td class="muted" style="font-size:.82rem;white-space:nowrap;"><?= e(date('M j, g:ia', strtotime($r['created_at']))) ?></td>
                <td>
                    <span class="badge badge-<?=
                        $r['type'] === 'deposit' ? 'approved' :
                        ($r['type'] === 'withdrawal' ? 'rejected' :
                        ($r['type'] === 'loan' ? 'info' : 'pending')) ?>">
                        <?= e(ucfirst(str_replace('_',' ', $r['type']))) ?>
                    </span>
                </td>
                <td style="text-align:right;font-weight:600;">
                    <?php if ($r['type'] === 'share_purchase'): ?>
                        <?= (int)$r['qty'] ?> shares
                    <?php else: ?>
                        <?= e(money((float)$r['amount'])) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-<?=
                        $r['status'] === 'approved' ? 'approved' :
                        ($r['status'] === 'rejected' ? 'rejected' :
                        ($r['status'] === 'cancelled' ? 'suspended' : 'pending')) ?>">
                        <?= e($r['status']) ?>
                    </span>
                </td>
                <td class="muted" style="font-size:.82rem;">
                    <?php if ($r['decision_reason']): ?>
                        <?= e($r['decision_reason']) ?>
                    <?php elseif ($r['decided_at']): ?>
                        <?= e(date('M j, Y', strtotime($r['decided_at']))) ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td style="text-align:right;">
                    <?php if ($r['status'] === 'pending'): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this request?');">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-ghost btn-sm">Cancel</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$requests): ?>
            <tr><td colspan="6" class="center muted" style="padding:48px;">
                <div style="font-size:2rem;margin-bottom:8px;">📭</div>
                <div style="font-weight:600;color:var(--text);">No requests yet</div>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>