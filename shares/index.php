<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/shares.php';

$totalCapital = total_share_capital($pdo);
$totalShares  = total_shares_outstanding($pdo);
$faceValue    = (float)(setting('share_face_value') ?? '10000');

// Distinct shareholders
$holders = (int)$pdo->query("
    SELECT COUNT(DISTINCT member_id)
    FROM share_purchases
    WHERE is_reversed = 0 AND (reversal_of_id IS NULL OR reversal_of_id = 0)
")->fetchColumn();

// Purchases this month
$thisMonth = $pdo->query("
    SELECT COALESCE(SUM(qty), 0) AS qty, COALESCE(SUM(total_amount), 0) AS amount
    FROM share_purchases
    WHERE is_reversed = 0 AND (reversal_of_id IS NULL OR reversal_of_id = 0)
      AND MONTH(purchase_date) = MONTH(CURDATE())
      AND YEAR(purchase_date) = YEAR(CURDATE())
")->fetch();

// Last declared dividend
$lastDividend = $pdo->query("
    SELECT * FROM dividends ORDER BY year DESC LIMIT 1
")->fetch();

// Top 10 shareholders
$top = $pdo->query("
    SELECT m.id, m.first_name, m.last_name, m.member_no,
           COALESCE(SUM(sp.qty), 0) AS shares,
           COALESCE(SUM(sp.total_amount), 0) AS value
    FROM members m
    JOIN share_purchases sp ON sp.member_id = m.id
    WHERE sp.is_reversed = 0 AND (sp.reversal_of_id IS NULL OR sp.reversal_of_id = 0)
    GROUP BY m.id
    HAVING shares > 0
    ORDER BY shares DESC
    LIMIT 10
")->fetchAll();

// Recent purchases
$recent = $pdo->query("
    SELECT sp.*, m.first_name, m.last_name, m.member_no, m.id AS member_id
    FROM share_purchases sp
    JOIN members m ON m.id = sp.member_id
    ORDER BY sp.created_at DESC, sp.id DESC
    LIMIT 8
")->fetchAll();

$pageTitle = 'Shares';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Share capital</h1>
        <p><?= e(setting('share_label', 'Ordinary Shares')) ?> · <?= e(money($faceValue)) ?> per share</p>
    </div>
    <div class="page-head-actions">
        <a href="history.php" class="btn btn-ghost">All purchases</a>
        <a href="dividends.php" class="btn btn-outline">Dividends</a>
        <a href="purchase.php" class="btn btn-primary">+ Purchase shares</a>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><?= e($msg) ?></div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat animate-fade-up">
        <div class="stat-head"><div><div class="stat-label">Total share capital</div></div><div class="stat-icon">🏦</div></div>
        <div class="stat-value"><?= e(money($totalCapital)) ?></div>
        <div class="stat-trend muted"><?= number_format($totalShares) ?> shares outstanding</div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-head"><div><div class="stat-label">Shareholders</div></div><div class="stat-icon">👥</div></div>
        <div class="stat-value"><?= number_format($holders) ?></div>
        <div class="stat-trend muted">Members holding shares</div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-head"><div><div class="stat-label">This month</div></div><div class="stat-icon">📈</div></div>
        <div class="stat-value"><?= number_format((int)$thisMonth['qty']) ?></div>
        <div class="stat-trend muted"><?= e(money((float)$thisMonth['amount'])) ?> raised</div>
    </div>
    <div class="stat animate-fade-up delay-3">
        <div class="stat-head"><div><div class="stat-label">Last dividend</div></div><div class="stat-icon">💵</div></div>
        <?php if ($lastDividend): ?>
            <div class="stat-value"><?= e(money((float)$lastDividend['distributable_amount'])) ?></div>
            <div class="stat-trend muted">
                <?= (int)$lastDividend['year'] ?> ·
                <span class="badge badge-<?= $lastDividend['status'] === 'paid' ? 'approved' : 'pending' ?>">
                    <?= e($lastDividend['status']) ?>
                </span>
            </div>
        <?php else: ?>
            <div class="stat-value" style="font-size:1.3rem;">None yet</div>
            <div class="stat-trend muted">No dividends declared</div>
        <?php endif; ?>
    </div>
</div>

<div class="dash-grid" style="margin-top:18px;">

    <div class="card animate-fade-up">
        <div class="card-title" style="margin-bottom:14px;">Top shareholders</div>
        <?php if ($top): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Member</th>
                            <th style="text-align:right;">Shares</th>
                            <th style="text-align:right;">Value</th>
                            <th style="text-align:right;">% of pool</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($top as $i => $t): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <a href="member.php?id=<?= (int)$t['id'] ?>" style="font-weight:600;">
                                    <?= e($t['first_name'] . ' ' . $t['last_name']) ?>
                                </a>
                                <div class="muted" style="font-size:.76rem;"><code><?= e($t['member_no']) ?></code></div>
                            </td>
                            <td style="text-align:right;font-weight:700;"><?= number_format((int)$t['shares']) ?></td>
                            <td style="text-align:right;"><?= e(money((float)$t['value'])) ?></td>
                            <td style="text-align:right;" class="muted">
                                <?= $totalShares > 0 ? number_format((int)$t['shares'] / $totalShares * 100, 2) . '%' : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="center muted" style="padding:40px;">
                <div style="font-size:2rem;margin-bottom:8px;">🏦</div>
                <div style="font-weight:600;color:var(--text);">No shares purchased yet</div>
                <div style="font-size:.85rem;margin-bottom:14px;">Record the first purchase to get started.</div>
                <a href="purchase.php" class="btn btn-primary btn-sm">+ Purchase shares</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="card animate-fade-up delay-1">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <div class="card-title">Recent purchases</div>
            <a href="history.php" class="btn btn-ghost btn-sm">View all →</a>
        </div>
        <?php if ($recent): ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($recent as $r): ?>
                    <?php $isReversed = (int)$r['is_reversed'] === 1 || (int)$r['reversal_of_id'] > 0; ?>
                    <a href="member.php?id=<?= (int)$r['member_id'] ?>"
                       style="display:block;text-decoration:none;color:inherit;padding:12px;border:1px solid var(--border-2);border-radius:10px;<?= $isReversed ? 'opacity:.55;' : '' ?>">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                            <div>
                                <div style="font-weight:600;font-size:.9rem;">
                                    <?= e($r['first_name'] . ' ' . $r['last_name']) ?>
                                </div>
                                <div class="muted" style="font-size:.76rem;">
                                    <?= (int)$r['qty'] ?> share<?= (int)$r['qty'] === 1 ? '' : 's' ?>
                                    · <?= e(date('M j, Y', strtotime($r['purchase_date']))) ?>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-weight:700;<?= $isReversed ? 'text-decoration:line-through;' : '' ?>">
                                    <?= e(money((float)$r['total_amount'])) ?>
                                </div>
                                <?php if ((int)$r['is_reversed']): ?>
                                    <div style="font-size:.72rem;color:var(--danger);">Reversed</div>
                                <?php elseif ((int)$r['reversal_of_id'] > 0): ?>
                                    <div style="font-size:.72rem;color:var(--text-3);">Reversal</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="center muted" style="padding:32px;">No purchases yet.</div>
        <?php endif; ?>
    </div>
</div>

<style>
.dash-grid { display:grid; grid-template-columns:1.4fr 1fr; gap:18px; }
@media (max-width:900px) { .dash-grid { grid-template-columns:1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>