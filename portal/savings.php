<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_member();
$member = current_member();
$memberId = (int)$member['id'];

$accountId = (int)($member['account_id'] ?? 0);
$accountNo = $member['account_no'] ?? '—';
$balance   = (float)($member['balance'] ?? 0);

$errors = [];
$old = ['from' => $_GET['from'] ?? date('Y-m-01'), 'to' => $_GET['to'] ?? date('Y-m-d')];
$valid = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$valid($old['from'])) $old['from'] = date('Y-m-01');
if (!$valid($old['to']))   $old['to']   = date('Y-m-d');
if (strtotime($old['from']) > strtotime($old['to'])) {
    [$old['from'], $old['to']] = [$old['to'], $old['from']];
}

$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

// Count
$cnt = $pdo->prepare("
    SELECT COUNT(*) FROM savings_transactions
    WHERE account_id = ? AND transaction_date BETWEEN ? AND ?
");
$cnt->execute([$accountId, $old['from'], $old['to']]);
$total = (int)$cnt->fetchColumn();
$pg = paginate($total, $perPage, $page);

$stmt = $pdo->prepare("
    SELECT * FROM savings_transactions
    WHERE account_id = ? AND transaction_date BETWEEN ? AND ?
    ORDER BY transaction_date DESC, id DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}
");
$stmt->execute([$accountId, $old['from'], $old['to']]);
$rows = $stmt->fetchAll();

// Totals (effective only)
$tot = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN type='deposit'    THEN amount ELSE 0 END), 0) AS deposits,
        COALESCE(SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END), 0) AS withdrawals
    FROM savings_transactions
    WHERE account_id = ? AND transaction_date BETWEEN ? AND ?
      AND is_reversed = 0 AND (reversal_of_id IS NULL OR reversal_of_id = 0)
");
$tot->execute([$accountId, $old['from'], $old['to']]);
$totals = $tot->fetch();

$pageTitle = 'My Savings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>My Savings</h1>
        <p>Account <code><?= e($accountNo) ?></code> · current balance <strong><?= e(money($balance)) ?></strong></p>
    </div>
    <div class="page-head-actions">
        <a href="statement.php" class="btn btn-ghost">Print statement</a>
        <a href="request-deposit.php" class="btn btn-outline">+ Request deposit</a>
        <a href="request-withdrawal.php" class="btn btn-primary">Request withdrawal</a>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<form method="get" class="card" style="padding:14px 18px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;">
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        From
        <input type="date" name="from" value="<?= e($old['from']) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        To
        <input type="date" name="to" value="<?= e($old['to']) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </label>
    <button class="btn btn-outline btn-sm" type="submit">Apply</button>
    <div style="margin-left:auto;display:flex;gap:8px;">
        <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-ghost btn-sm">This month</a>
        <a href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-ghost btn-sm">This year</a>
    </div>
</form>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
    <div class="stat animate-fade-up">
        <div class="stat-label">Current balance</div>
        <div class="stat-value"><?= e(money($balance)) ?></div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-label">Deposits (period)</div>
        <div class="stat-value" style="color:var(--accent);">+<?= e(money((float)$totals['deposits'])) ?></div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-label">Withdrawals (period)</div>
        <div class="stat-value" style="color:var(--danger);">−<?= e(money((float)$totals['withdrawals'])) ?></div>
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
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php
                $isReversed = (int)$r['is_reversed'] === 1;
                $isReversal = (int)$r['reversal_of_id'] > 0;
                $muted = $isReversed || $isReversal;
            ?>
            <tr style="<?= $muted ? 'opacity:.55;' : '' ?>">
                <td style="white-space:nowrap;"><?= e(date('M j, Y', strtotime($r['transaction_date']))) ?></td>
                <td><code style="font-size:.78rem;"><?= e($r['reference']) ?></code></td>
                <td>
                    <span class="badge badge-<?= $r['type'] === 'deposit' ? 'approved' : 'rejected' ?>">
                        <?= e(ucfirst($r['type'])) ?>
                    </span>
                </td>
                <td style="text-align:right;font-weight:600;color:<?= $r['type'] === 'deposit' ? 'var(--accent)' : 'var(--danger)' ?>;<?= $muted ? 'text-decoration:line-through;' : '' ?>">
                    <?= $r['type'] === 'deposit' ? '+' : '−' ?><?= e(money((float)$r['amount'])) ?>
                </td>
                <td style="text-align:right;"><?= e(money((float)$r['balance_after'])) ?></td>
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
                        <a href="/scms/savings/receipt.php?txn_id=<?= (int)$r['id'] ?>" class="btn btn-ghost btn-sm" target="_blank" title="Receipt">🧾</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="7" class="center muted" style="padding:48px;">
                <div style="font-size:2rem;margin-bottom:8px;">📭</div>
                <div style="font-weight:600;color:var(--text);">No transactions in this period</div>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($pg['pages'] > 1): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;flex-wrap:wrap;gap:12px;">
        <div class="muted" style="font-size:.85rem;">
            Showing <?= $pg['offset'] + 1 ?>–<?= min($pg['offset'] + $pg['per_page'], $pg['total']) ?>
            of <?= number_format($pg['total']) ?>
        </div>
        <div style="display:flex;gap:6px;">
            <a href="<?= e(qs(['page' => max(1, $pg['current'] - 1)])) ?>" class="btn btn-outline btn-sm"
               <?= $pg['current'] <= 1 ? 'style="pointer-events:none;opacity:.4;"' : '' ?>>← Prev</a>
            <a href="<?= e(qs(['page' => min($pg['pages'], $pg['current'] + 1)])) ?>" class="btn btn-outline btn-sm"
               <?= $pg['current'] >= $pg['pages'] ? 'style="pointer-events:none;opacity:.4;"' : '' ?>>Next →</a>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>