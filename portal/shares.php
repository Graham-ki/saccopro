<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_member();
$member = current_member();
$memberId = (int)$member['id'];
require_once __DIR__ . '/../includes/shares.php';

$sharesHeld = member_shares_held($pdo, $memberId);
$faceValue = (float)(setting('share_face_value') ?? '10000');
$totalValue = $sharesHeld * $faceValue;
$totalPool = total_shares_outstanding($pdo);
$ownershipPct = $totalPool > 0 ? ($sharesHeld / $totalPool * 100) : 0;

$purchases = $pdo->prepare("
    SELECT * FROM share_purchases WHERE member_id = ?
    ORDER BY purchase_date DESC, id DESC
");
$purchases->execute([$memberId]);
$purchases = $purchases->fetchAll();

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

$pageTitle = 'My Shares';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>My Shares</h1>
        <p><?= e(setting('share_label', 'Ordinary Shares')) ?> at <?= e(money($faceValue)) ?> per share</p>
    </div>
    <div class="page-head-actions">
        <a href="request-shares.php" class="btn btn-primary">+ Buy shares</a>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
    <div class="stat">
        <div class="stat-label">Shares held</div>
        <div class="stat-value"><?= number_format($sharesHeld) ?></div>
        <div class="stat-trend muted"><?= e(money($totalValue)) ?> value</div>
    </div>
    <div class="stat">
        <div class="stat-label">Ownership</div>
        <div class="stat-value"><?= number_format($ownershipPct, 2) ?>%</div>
        <div class="stat-trend muted">of total pool</div>
    </div>
    <div class="stat">
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
                    <tr><th>Date</th><th>Receipt</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Total</th><th>Status</th></tr>
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
                        <td><code style="font-size:.78rem;"><?= e($p['receipt_no']) ?></code></td>
                        <td style="text-align:right;font-weight:600;<?= $muted ? 'text-decoration:line-through;' : '' ?>"><?= number_format((int)$p['qty']) ?></td>
                        <td style="text-align:right;font-weight:600;<?= $muted ? 'text-decoration:line-through;' : '' ?>"><?= e(money((float)$p['total_amount'])) ?></td>
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
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="center muted" style="padding:32px;">No share purchases yet.</div>
    <?php endif; ?>
</div>

<?php if ($dividends): ?>
    <div class="card animate-fade-up delay-1" style="margin-top:18px;">
        <h3 class="card-title" style="margin-bottom:14px;">Dividend history</h3>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Year</th><th style="text-align:right;">Shares</th><th style="text-align:right;">Amount</th><th>Status</th><th>Paid</th></tr>
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