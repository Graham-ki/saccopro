<?php
$pageTitle = 'Loan Repayments';
require_once __DIR__ . '/../includes/header.php';

$search = trim($_GET['q'] ?? '');
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(l.loan_no LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ? OR r.reference LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
if ($from) { $where[] = "r.payment_date >= ?"; $params[] = $from; }
if ($to)   { $where[] = "r.payment_date <= ?"; $params[] = $to; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$base = "FROM loan_repayments r
         JOIN loans l ON l.id = r.loan_id
         JOIN members m ON m.id = l.member_id
         $whereSql";

$cnt = $pdo->prepare("SELECT COUNT(*) $base");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pg = paginate($total, $perPage, $page);

$stmt = $pdo->prepare("
    SELECT r.*, l.loan_no, m.first_name, m.last_name, m.member_no, l.id AS loan_id
    $base
    ORDER BY r.payment_date DESC, r.id DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$sum = $pdo->prepare("SELECT COALESCE(SUM(r.amount),0) $base");
$sum->execute($params);
$filteredTotal = (float)$sum->fetchColumn();
?>

<div class="page-head">
    <div>
        <h1>Repayments</h1>
        <p>Every repayment recorded against loans.</p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Loans</a>
        <a href="create.php" class="btn btn-primary">+ Issue loan</a>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
    <div class="stat animate-fade-up">
        <div class="stat-head"><div><div class="stat-label">Filtered total</div></div><div class="stat-icon">💰</div></div>
        <div class="stat-value"><?= e(money($filteredTotal)) ?></div>
        <div class="stat-trend muted"><?= number_format($total) ?> repayments</div>
    </div>
</div>

<form method="get" class="card" style="padding:14px 18px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin:18px 0;">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search ref, loan no, member…"
           style="flex:1;min-width:220px;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    <input type="date" name="from" value="<?= e($from) ?>" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    <input type="date" name="to" value="<?= e($to) ?>" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    <button class="btn btn-outline btn-sm" type="submit">Apply</button>
    <?php if ($search || $from || $to): ?><a href="repayments.php" class="btn btn-ghost btn-sm">Reset</a><?php endif; ?>
</form>

<div class="table-wrap animate-fade-in">
    <table class="table">
        <thead>
    <tr>
        <th>Reference</th>
        <th>Date</th>
        <th>Loan</th>
        <th>Member</th>
        <th style="text-align:right;">Amount</th>
        <th style="text-align:right;">Principal</th>
        <th style="text-align:right;">Interest</th>
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
        <td style="white-space:nowrap;"><?= e(date('M j, Y', strtotime($r['payment_date']))) ?></td>
        <td><a href="view.php?id=<?= (int)$r['loan_id'] ?>"><code><?= e($r['loan_no']) ?></code></a></td>
        <td><?= e($r['first_name'] . ' ' . $r['last_name']) ?></td>
        <td style="text-align:right;font-weight:700;"><?= e(money((float)$r['amount'])) ?></td>
        <td style="text-align:right;"><?= e(money((float)$r['principal_portion'])) ?></td>
        <td style="text-align:right;"><?= e(money((float)$r['interest_portion'])) ?></td>
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
            <?php if (!(int)$r['is_reversed'] && (int)$r['reversal_of_id'] === 0): ?>
                <a href="reverse.php?repayment_id=<?= (int)$r['id'] ?>" class="btn btn-ghost btn-sm" title="Reverse">↩</a>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
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