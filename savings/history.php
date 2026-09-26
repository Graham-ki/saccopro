<?php
$pageTitle = 'Savings Transactions';
require_once __DIR__ . '/../includes/header.php';

$search   = trim($_GET['q'] ?? '');
$type     = $_GET['type'] ?? 'all';
$from     = $_GET['from'] ?? '';
$to       = $_GET['to'] ?? '';
$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));

$validType = ['all','deposit','withdrawal'];
if (!in_array($type, $validType, true)) $type = 'all';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_no LIKE ? OR sa.account_no LIKE ? OR st.reference LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like, $like);
}
if ($type !== 'all') { $where[] = "st.type = ?"; $params[] = $type; }
if ($from !== '')     { $where[] = "st.transaction_date >= ?"; $params[] = $from; }
if ($to !== '')       { $where[] = "st.transaction_date <= ?"; $params[] = $to; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$base = "FROM savings_transactions st
         JOIN savings_accounts sa ON sa.id = st.account_id
         JOIN members m ON m.id = sa.member_id
         $whereSql";

$countStmt = $pdo->prepare("SELECT COUNT(*) $base");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pg = paginate($total, $perPage, $page);

$stmt = $pdo->prepare("
    SELECT st.*, sa.account_no, m.id AS member_id, m.first_name, m.last_name, m.member_no
    $base
    ORDER BY st.transaction_date DESC, st.id DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Aggregate for the current filter
$agg = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN st.type='deposit'    THEN st.amount ELSE 0 END), 0) AS deposits,
        COALESCE(SUM(CASE WHEN st.type='withdrawal' THEN st.amount ELSE 0 END), 0) AS withdrawals
    $base
");
$agg->execute($params);
$totals = $agg->fetch();
?>

<div class="page-head">
    <div>
        <h1>Transaction history</h1>
        <p>Every deposit and withdrawal in the ledger.</p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Savings</a>
        <a href="deposit.php" class="btn btn-primary">+ Deposit</a>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<!-- Summary for filter -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
    <div class="stat animate-fade-up">
        <div class="stat-head"><div><div class="stat-label">Deposits (filtered)</div></div><div class="stat-icon">⬆️</div></div>
        <div class="stat-value" style="color:var(--accent);"><?= e(money($totals['deposits'])) ?></div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-head"><div><div class="stat-label">Withdrawals (filtered)</div></div><div class="stat-icon">⬇️</div></div>
        <div class="stat-value" style="color:var(--danger);"><?= e(money($totals['withdrawals'])) ?></div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-head"><div><div class="stat-label">Net</div></div><div class="stat-icon">📊</div></div>
        <div class="stat-value"><?= e(money((float)$totals['deposits'] - (float)$totals['withdrawals'])) ?></div>
    </div>
</div>

<!-- Filters -->
<form method="get" class="card" style="padding:14px 18px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:18px;">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search ref, member, account…"
           style="flex:1;min-width:200px;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    <select name="type" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
        <option value="all"        <?= $type === 'all' ? 'selected' : '' ?>>All types</option>
        <option value="deposit"    <?= $type === 'deposit' ? 'selected' : '' ?>>Deposits</option>
        <option value="withdrawal" <?= $type === 'withdrawal' ? 'selected' : '' ?>>Withdrawals</option>
    </select>
    <input type="date" name="from" value="<?= e($from) ?>" title="From"
           style="padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    <input type="date" name="to" value="<?= e($to) ?>" title="To"
           style="padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    <button class="btn btn-outline btn-sm" type="submit">Apply</button>
    <?php if ($search || $type !== 'all' || $from || $to): ?>
        <a href="history.php" class="btn btn-ghost btn-sm">Reset</a>
    <?php endif; ?>
</form>

<!-- Ledger -->
<div class="table-wrap animate-fade-in">
    <table class="table">
        <thead>
    <tr>
        <th>Reference</th>
        <th>Date</th>
        <th>Member</th>
        <th>Account</th>
        <th>Type</th>
        <th style="text-align:right;">Amount</th>
        <th style="text-align:right;">Balance after</th>
        <th>Status</th>
        <th></th>
    </tr>
</thead>
        <tbody>
<?php foreach ($rows as $r): ?>
    <tr style="<?= (int)$r['is_reversed'] ? 'opacity:.55;' : '' ?>">
        <td>
            <code style="font-size:.78rem;"><?= e($r['reference']) ?></code>
            <?php if ((int)$r['reversal_of_id'] > 0): ?>
                <div class="muted" style="font-size:.7rem;">reverse of #<?= (int)$r['reversal_of_id'] ?></div>
            <?php endif; ?>
        </td>
        <td style="white-space:nowrap;"><?= e(date('M j, Y', strtotime($r['transaction_date']))) ?></td>
        <td>
            <a href="/scms/members/view.php?id=<?= (int)$r['member_id'] ?>">
                <?= e($r['first_name'] . ' ' . $r['last_name']) ?>
            </a>
        </td>
        <td><code style="font-size:.8rem;"><?= e($r['account_no']) ?></code></td>
        <td>
            <?php if ($r['type'] === 'deposit'): ?>
                <span class="badge badge-approved">Deposit</span>
            <?php else: ?>
                <span class="badge badge-rejected">Withdrawal</span>
            <?php endif; ?>
        </td>
        <td style="text-align:right;font-weight:600;color:<?= $r['type'] === 'deposit' ? 'var(--accent)' : 'var(--danger)' ?>;">
            <?= $r['type'] === 'deposit' ? '+' : '−' ?><?= e(money((float)$r['amount'])) ?>
        </td>
        <td style="text-align:right;"><?= e(money((float)$r['balance_after'])) ?></td>
        <td>
            <?php if ((int)$r['is_reversed']): ?>
                <span class="badge badge-rejected">Reversed</span>
            <?php elseif ((int)$r['reversal_of_id'] > 0): ?>
                <span class="badge badge-pending">Reversal</span>
            <?php else: ?>
                <span class="badge badge-approved">Posted</span>
            <?php endif; ?>
        </td>
        
        <td style="text-align:right;">
            <a href="/scms/savings/receipt.php?txn_id=<?= (int)$r['id'] ?>" class="btn btn-ghost btn-sm" title="Print receipt" target="_blank">🧾</a>
            <?php if (!(int)$r['is_reversed'] && (int)$r['reversal_of_id'] === 0): ?>
                <a href="/scms/savings/reverse.php?txn_id=<?= (int)$r['id'] ?>" class="btn btn-ghost btn-sm" title="Reverse">↩</a>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($pg['pages'] > 1): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;flex-wrap:wrap;gap:12px;">
        <div class="muted" style="font-size:.85rem;">
            Showing <?= $pg['offset'] + 1 ?>–<?= min($pg['offset'] + $pg['per_page'], $pg['total']) ?>
            of <?= number_format($pg['total']) ?> transactions
        </div>
        <div style="display:flex;gap:6px;">
            <a href="<?= e(qs(['page' => max(1, $pg['current'] - 1)])) ?>" class="btn btn-outline btn-sm"
               <?= $pg['current'] <= 1 ? 'style="pointer-events:none;opacity:.4;"' : '' ?>>← Prev</a>
            <?php for ($i = max(1, $pg['current'] - 2); $i <= min($pg['pages'], $pg['current'] + 2); $i++): ?>
                <a href="<?= e(qs(['page' => $i])) ?>" class="btn btn-sm <?= $i === $pg['current'] ? 'btn-primary' : 'btn-outline' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <a href="<?= e(qs(['page' => min($pg['pages'], $pg['current'] + 1)])) ?>" class="btn btn-outline btn-sm"
               <?= $pg['current'] >= $pg['pages'] ? 'style="pointer-events:none;opacity:.4;"' : '' ?>>Next →</a>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>