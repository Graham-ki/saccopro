<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_member();
$member = current_member();
$memberId = (int)$member['id'];

$accountId = (int)($member['account_id'] ?? 0);
if (!$accountId) {
    flash('error', 'You do not have a savings account yet.');
    redirect('/scms/portal/index.php');
}

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');
$valid = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$valid($from)) $from = date('Y-m-01');
if (!$valid($to))   $to = date('Y-m-d');
if (strtotime($from) > strtotime($to)) [$from, $to] = [$to, $from];

// Effective statement (excluding reversed + reversal)
$stmt = $pdo->prepare("
    SELECT st.*, u.full_name AS recorded_by_name
    FROM savings_transactions st
    LEFT JOIN users u ON u.id = st.recorded_by
    WHERE st.account_id = ? AND st.transaction_date BETWEEN ? AND ?
    ORDER BY st.transaction_date ASC, st.id ASC
");
$stmt->execute([$accountId, $from, $to]);
$all = $stmt->fetchAll();

// Effective opening = balance from all non-reversed, non-reversal rows before $from
$opening = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE -amount END), 0)
    FROM savings_transactions
    WHERE account_id = ? AND transaction_date < ?
      AND is_reversed = 0 AND (reversal_of_id IS NULL OR reversal_of_id = 0)
");
$opening->execute([$accountId, $from]);
$openingBalance = (float)$opening->fetchColumn();

$deposits = 0.0;
$withdrawals = 0.0;
foreach ($all as $t) {
    if ((int)$t['is_reversed'] === 1 || (int)$t['reversal_of_id'] > 0) continue;
    if ($t['type'] === 'deposit') $deposits += (float)$t['amount'];
    else $withdrawals += (float)$t['amount'];
}
$closing = $openingBalance + $deposits - $withdrawals;

$initial = strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1));

$pageTitle = 'Statement';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
@media print {
    .sidebar, .topbar, .page-head-actions, .sidebar-backdrop, form.card { display:none !important; }
    .main { margin-left:0 !important; } .page { padding:0 !important; }
    .card, .table-wrap { box-shadow:none !important; border-color:#ccc !important; }
}
</style>
<div class="page-head">
    <div>
        <h1>Account statement</h1>
        <p><?= e($member['first_name'] . ' ' . $member['last_name']) ?> · <code><?= e($member['account_no']) ?></code></p>
    </div>
    <div class="page-head-actions">
        <a href="savings.php" class="btn btn-ghost">← Savings</a>
        <a href="statement-export.php?from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-outline">⬇ CSV</a>
        <button onclick="window.print()" class="btn btn-primary">🖨️ Print</button>
    </div>
</div>

<form method="get" class="card" style="padding:14px 18px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:18px;">
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        From
        <input type="date" name="from" value="<?= e($from) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        To
        <input type="date" name="to" value="<?= e($to) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </label>
    <button class="btn btn-outline btn-sm" type="submit">Apply</button>
</form>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
    <div class="stat">
        <div class="stat-label">Opening</div>
        <div class="stat-value" style="font-size:1.3rem;"><?= e(money($openingBalance)) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Deposits</div>
        <div class="stat-value" style="font-size:1.3rem;color:var(--accent);">+<?= e(money($deposits)) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Withdrawals</div>
        <div class="stat-value" style="font-size:1.3rem;color:var(--danger);">−<?= e(money($withdrawals)) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Closing</div>
        <div class="stat-value" style="font-size:1.3rem;"><?= e(money($closing)) ?></div>
    </div>
</div>

<div class="table-wrap animate-fade-in" style="margin-top:18px;">
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Type</th>
                <th style="text-align:right;">Amount</th>
                <th style="text-align:right;">Balance</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <tr style="background:var(--surface-2);">
                <td colspan="4"><em class="muted">Opening balance</em></td>
                <td style="text-align:right;font-weight:700;"><?= e(money($openingBalance)) ?></td>
                <td></td>
            </tr>
            <?php foreach ($all as $t): ?>
                <?php
                    $isReversed = (int)$t['is_reversed'] === 1;
                    $isReversal = (int)$t['reversal_of_id'] > 0;
                    $muted = $isReversed || $isReversal;
                ?>
                <tr style="<?= $muted ? 'opacity:.55;' : '' ?>">
                    <td style="white-space:nowrap;"><?= e(date('M j, Y', strtotime($t['transaction_date']))) ?></td>
                    <td><code style="font-size:.78rem;"><?= e($t['reference']) ?></code></td>
                    <td>
                        <span class="badge badge-<?= $t['type'] === 'deposit' ? 'approved' : 'rejected' ?>">
                            <?= e(ucfirst($t['type'])) ?>
                        </span>
                    </td>
                    <td style="text-align:right;font-weight:600;color:<?= $t['type'] === 'deposit' ? 'var(--accent)' : 'var(--danger)' ?>;<?= $muted ? 'text-decoration:line-through;' : '' ?>">
                        <?= $t['type'] === 'deposit' ? '+' : '−' ?><?= e(money((float)$t['amount'])) ?>
                    </td>
                    <td style="text-align:right;"><?= e(money((float)$t['balance_after'])) ?></td>
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
            <tr style="background:var(--surface-2);border-top:2px solid var(--border);">
                <td colspan="4" style="font-weight:700;">Closing balance</td>
                <td style="text-align:right;font-weight:800;"><?= e(money($closing)) ?></td>
                <td></td>
            </tr>
        </tbody>
    </table>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>