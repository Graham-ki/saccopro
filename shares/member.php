<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/shares.php';

$memberId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM members WHERE id = ? LIMIT 1");
$stmt->execute([$memberId]);
$m = $stmt->fetch();

if (!$m) {
    flash('error', 'Member not found.');
    redirect('/scms/shares/index.php');
}

$purchases = $pdo->prepare("
    SELECT sp.*, u.full_name AS recorded_by_name
    FROM share_purchases sp
    LEFT JOIN users u ON u.id = sp.recorded_by
    WHERE sp.member_id = ?
    ORDER BY sp.purchase_date DESC, sp.id DESC
");
$purchases->execute([$memberId]);
$purchases = $purchases->fetchAll();

$sharesHeld = member_shares_held($pdo, $memberId);
$totalValue = $sharesHeld * (float)(setting('share_face_value') ?? '10000');
$totalShares = total_shares_outstanding($pdo);
$ownershipPct = $totalShares > 0 ? ($sharesHeld / $totalShares * 100) : 0;

// Dividends received by this member
$dividends = $pdo->prepare("
    SELECT dp.*, d.year, d.status AS dividend_status
    FROM dividend_payouts dp
    JOIN dividends d ON d.id = dp.dividend_id
    WHERE dp.member_id = ?
    ORDER BY d.year DESC
");
$dividends->execute([$memberId]);
$dividends = $dividends->fetchAll();

$dividendsReceived = array_sum(array_map(
    fn($d) => $d['paid_at'] ? (float)$d['amount'] : 0,
    $dividends
));

$initial = strtoupper(substr($m['first_name'], 0, 1) . substr($m['last_name'], 0, 1));

$pageTitle = 'Share Statement';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div style="display:flex;align-items:center;gap:16px;">
        <div class="avatar" style="width:56px;height:56px;font-size:1.2rem;border-radius:14px;"><?= e($initial) ?></div>
        <div>
            <h1 style="margin-bottom:2px;"><?= e($m['first_name'] . ' ' . $m['last_name']) ?></h1>
            <p style="margin:0;">
                <code><?= e($m['member_no']) ?></code> · Share statement
            </p>
        </div>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Shares</a>
        <a href="purchase.php?member_id=<?= $memberId ?>" class="btn btn-primary">+ Purchase more</a>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
    <div class="stat animate-fade-up">
        <div class="stat-label">Shares held</div>
        <div class="stat-value"><?= number_format($sharesHeld) ?></div>
        <div class="stat-trend muted"><?= e(money($totalValue)) ?> value</div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-label">Ownership</div>
        <div class="stat-value"><?= number_format($ownershipPct, 2) ?>%</div>
        <div class="stat-trend muted">of total pool</div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-label">Dividends received</div>
        <div class="stat-value" style="color:var(--accent);"><?= e(money($dividendsReceived)) ?></div>
        <div class="stat-trend muted"><?= count(array_filter($dividends, fn($d) => $d['paid_at'])) ?> payouts</div>
    </div>
</div>

<div class="card animate-fade-up" style="margin-top:18px;">
    <h3 class="card-title" style="margin-bottom:14px;">Purchase history</h3>
    <?php if ($purchases): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Receipt</th>
                        <th style="text-align:right;">Qty</th>
                        <th style="text-align:right;">Unit</th>
                        <th style="text-align:right;">Total</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($purchases as $p): ?>
                    <?php
                        $isReversed = (int)$p['is_reversed'] === 1;
                        $isReversal = (int)$p['reversal_of_id'] > 0;
                        $muted = $isReversed || $isReversal;
                    ?>
                    <tr style="<?= $muted ? 'opacity:.55;' : '' ?>">
                        <td><?= e(date('M j, Y', strtotime($p['purchase_date']))) ?></td>
                        <td>
                            <code style="font-size:.78rem;"><?= e($p['receipt_no']) ?></code>
                            <?php if ($isReversal): ?>
                                <div class="muted" style="font-size:.7rem;">reverse of #<?= (int)$p['reversal_of_id'] ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;font-weight:600;<?= $muted ? 'text-decoration:line-through;' : '' ?>">
                            <?= number_format((int)$p['qty']) ?>
                        </td>
                        <td style="text-align:right;"><?= e(money((float)$p['unit_price'])) ?></td>
                        <td style="text-align:right;font-weight:600;<?= $muted ? 'text-decoration:line-through;' : '' ?>">
                            <?= e(money((float)$p['total_amount'])) ?>
                        </td>
                        <td><span class="badge badge-info"><?= e(ucfirst(str_replace('_',' ', $p['method']))) ?></span></td>
                        <td>
                            <?php if ($isReversed): ?>
                                <span class="badge badge-rejected">Reversed</span>
                            <?php elseif ($isReversal): ?>
                                <span class="badge badge-pending">Reversal</span>
                            <?php else: ?>
                                <span class="badge badge-approved">Posted</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <?php if (!$muted): ?>
                                <a href="reverse.php?id=<?= (int)$p['id'] ?>" class="btn btn-ghost btn-sm" title="Reverse">↩</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="center muted" style="padding:40px;">
            <div style="font-size:2rem;margin-bottom:8px;">📄</div>
            <div style="font-weight:600;color:var(--text);">No share purchases yet</div>
            <div style="font-size:.85rem;margin-bottom:14px;">Record the first purchase.</div>
            <a href="purchase.php?member_id=<?= $memberId ?>" class="btn btn-primary btn-sm">+ Purchase shares</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($dividends): ?>
    <div class="card animate-fade-up delay-1" style="margin-top:18px;">
        <h3 class="card-title" style="margin-bottom:14px;">Dividends</h3>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Year</th>
                        <th style="text-align:right;">Shares at declaration</th>
                        <th style="text-align:right;">Amount</th>
                        <th>Eligible</th>
                        <th>Paid</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($dividends as $d): ?>
                    <tr>
                        <td><strong><?= (int)$d['year'] ?></strong></td>
                        <td style="text-align:right;"><?= number_format((int)$d['shares_held']) ?></td>
                        <td style="text-align:right;font-weight:700;"><?= e(money((float)$d['amount'])) ?></td>
                        <td>
                            <?php if ((int)$d['eligible']): ?>
                                <span class="badge badge-approved">Eligible</span>
                            <?php else: ?>
                                <span class="badge badge-rejected"><?= e($d['ineligible_reason'] ?: 'Ineligible') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($d['paid_at']): ?>
                                <span class="badge badge-approved"><?= e(date('M j, Y', strtotime($d['paid_at']))) ?></span>
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
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>