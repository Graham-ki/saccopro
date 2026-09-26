<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/shares.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

// ---- Handle POST (pay / cancel) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'pay') {
        try {
            $result = pay_dividend($pdo, $id, (int)$user['id'], 'savings');
            audit_log($pdo, (int)$user['id'], 'dividend.pay', 'dividend', $id,
                "Paid {$result['posted']} members, total " . money($result['total_paid']));
            flash('success', "Dividends paid to {$result['posted']} member(s) — " . money($result['total_paid']));
            redirect('/scms/shares/dividend-view.php?id=' . $id);
        } catch (Throwable $ex) {
            flash('error', $ex->getMessage());
            redirect('/scms/shares/dividend-view.php?id=' . $id);
        }
    }
}

$stmt = $pdo->prepare("
    SELECT d.*, u.full_name AS declared_by_name
    FROM dividends d
    LEFT JOIN users u ON u.id = d.declared_by
    WHERE d.id = ? LIMIT 1
");
$stmt->execute([$id]);
$div = $stmt->fetch();

if (!$div) {
    flash('error', 'Dividend not found.');
    redirect('/scms/shares/dividends.php');
}

$payouts = $pdo->prepare("
    SELECT dp.*, m.first_name, m.last_name, m.member_no,
           u.full_name AS paid_by_name
    FROM dividend_payouts dp
    JOIN members m ON m.id = dp.member_id
    LEFT JOIN users u ON u.id = dp.paid_by
    WHERE dp.dividend_id = ?
    ORDER BY dp.eligible DESC, dp.amount DESC
");
$payouts->execute([$id]);
$payouts = $payouts->fetchAll();

$eligibleCount = count(array_filter($payouts, fn($p) => (int)$p['eligible']));
$paidCount = count(array_filter($payouts, fn($p) => $p['paid_at']));
$totalEligibleAmount = array_sum(array_map(fn($p) => (int)$p['eligible'] ? (float)$p['amount'] : 0, $payouts));

$pageTitle = 'Dividend ' . $div['year'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Dividend <?= (int)$div['year'] ?></h1>
        <p>
            Declared <?= e(date('M j, Y', strtotime($div['declared_at']))) ?>
            <?php if ($div['declared_by_name']): ?>by <?= e($div['declared_by_name']) ?><?php endif; ?>
            · <span class="badge badge-<?= $div['status'] === 'paid' ? 'approved' : ($div['status'] === 'cancelled' ? 'rejected' : 'pending') ?>"><?= e($div['status']) ?></span>
        </p>
    </div>
    <div class="page-head-actions">
        <a href="dividends.php" class="btn btn-ghost">← All dividends</a>
        <?php if ($div['status'] === 'declared' && $paidCount < $eligibleCount): ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('Pay dividends to all eligible members? Money will be deposited to their savings accounts.');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="pay">
                <button class="btn btn-primary">✓ Pay <?= $eligibleCount - $paidCount ?> member(s)</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><?= e($msg) ?></div>
<?php endif; ?>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
    <div class="stat animate-fade-up">
        <div class="stat-label">Profit pool</div>
        <div class="stat-value"><?= e(money((float)$div['profit_pool'])) ?></div>
        <div class="stat-trend muted">
            <?= $div['override_profit'] !== null ? 'Override' : 'Computed' ?>
            · <?= e(number_format((float)$div['distributable_pct'], 1)) ?>% distributed
        </div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-label">Distributable</div>
        <div class="stat-value" style="color:var(--accent);"><?= e(money((float)$div['distributable_amount'])) ?></div>
        <div class="stat-trend muted"><?= number_format((int)$div['total_eligible_shares']) ?> eligible shares</div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-label">Eligible members</div>
        <div class="stat-value"><?= $eligibleCount ?></div>
        <div class="stat-trend muted">Min <?= (int)$div['min_membership_months'] ?> months</div>
    </div>
    <div class="stat animate-fade-up delay-3">
        <div class="stat-label">Paid</div>
        <div class="stat-value" style="color:var(--accent);"><?= e(money((float)$div['total_paid'])) ?></div>
        <div class="stat-trend muted"><?= $paidCount ?>/<?= $eligibleCount ?> members</div>
    </div>
</div>

<?php if (!empty($div['basis_note'])): ?>
    <div class="card animate-fade-up" style="margin-top:18px;">
        <div class="muted" style="font-size:.78rem;text-transform:uppercase;">Basis note</div>
        <div style="margin-top:6px;"><?= nl2br(e($div['basis_note'])) ?></div>
    </div>
<?php endif; ?>

<div class="card animate-fade-up delay-1" style="margin-top:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
        <div>
            <div class="card-title">Member payouts</div>
            <div class="card-sub">
                <?= count($payouts) ?> member<?= count($payouts) === 1 ? '' : 's' ?> on this dividend
            </div>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Member</th>
                    <th style="text-align:right;">Shares</th>
                    <th style="text-align:right;">Months</th>
                    <th>Eligible</th>
                    <th style="text-align:right;">Amount</th>
                    <th>Paid</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payouts as $p): ?>
                <tr style="<?= !(int)$p['eligible'] ? 'opacity:.55;' : '' ?>">
                    <td>
                        <a href="member.php?id=<?= (int)$p['member_id'] ?>" style="font-weight:600;">
                            <?= e($p['first_name'] . ' ' . $p['last_name']) ?>
                        </a>
                        <div class="muted" style="font-size:.76rem;"><code><?= e($p['member_no']) ?></code></div>
                    </td>
                    <td style="text-align:right;font-weight:600;"><?= number_format((int)$p['shares_held']) ?></td>
                    <td style="text-align:right;"><?= (int)$p['months_membership'] ?></td>
                    <td>
                        <?php if ((int)$p['eligible']): ?>
                            <span class="badge badge-approved">Eligible</span>
                        <?php else: ?>
                            <span class="badge badge-rejected"><?= e($p['ineligible_reason'] ?: 'Ineligible') ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;font-weight:700;"><?= e(money((float)$p['amount'])) ?></td>
                    <td>
                        <?php if ($p['paid_at']): ?>
                            <span class="badge badge-approved"><?= e(date('M j, Y', strtotime($p['paid_at']))) ?></span>
                        <?php else: ?>
                            <span class="muted">Pending</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>