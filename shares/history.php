<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/shares.php';

$search = trim((string)($_GET['q'] ?? ''));
$from   = $_GET['from'] ?? '';
$to     = $_GET['to']   ?? '';
$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(m.first_name LIKE :s1 OR m.last_name LIKE :s2 OR m.member_no LIKE :s3 OR sp.receipt_no LIKE :s4)";
    $params[':s1'] = "%$search%";
    $params[':s2'] = "%$search%";
    $params[':s3'] = "%$search%";
    $params[':s4'] = "%$search%";
}
if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = "sp.purchase_date >= :from";
    $params[':from'] = $from;
}
if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = "sp.purchase_date <= :to";
    $params[':to'] = $to;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$base = "FROM share_purchases sp JOIN members m ON m.id = sp.member_id $whereSql";

$cnt = $pdo->prepare("SELECT COUNT(*) $base");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pg = paginate($total, $perPage, $page);

$stmt = $pdo->prepare("
    SELECT sp.*, m.first_name, m.last_name, m.member_no, m.id AS member_id,
           u.full_name AS recorded_by_name
    $base
    LEFT JOIN users u ON u.id = sp.recorded_by
    ORDER BY sp.purchase_date DESC, sp.id DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Share Purchases';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Share purchase history</h1>
        <p><?= number_format($total) ?> record<?= $total === 1 ? '' : 's' ?></p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Shares</a>
        <a href="purchase.php" class="btn btn-primary">+ Purchase shares</a>
    </div>
</div>

<form method="get" class="card" style="padding:14px 18px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:18px;">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search member, receipt…"
           style="flex:1;min-width:200px;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    <input type="date" name="from" value="<?= e($from) ?>" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    <input type="date" name="to"   value="<?= e($to) ?>"   style="padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    <button class="btn btn-outline btn-sm" type="submit">Apply</button>
    <?php if ($search || $from || $to): ?><a href="history.php" class="btn btn-ghost btn-sm">Reset</a><?php endif; ?>
</form>

<div class="table-wrap animate-fade-in">
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Receipt</th>
                <th>Member</th>
                <th style="text-align:right;">Qty</th>
                <th style="text-align:right;">Total</th>
                <th>Method</th>
                <th>Status</th>
                <th>Recorded by</th>
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
                <td><?= e(date('M j, Y', strtotime($r['purchase_date']))) ?></td>
                <td><code style="font-size:.78rem;"><?= e($r['receipt_no']) ?></code></td>
                <td>
                    <a href="member.php?id=<?= (int)$r['member_id'] ?>" style="font-weight:600;">
                        <?= e($r['first_name'] . ' ' . $r['last_name']) ?>
                    </a>
                    <div class="muted" style="font-size:.76rem;"><code><?= e($r['member_no']) ?></code></div>
                </td>
                <td style="text-align:right;font-weight:700;<?= $muted ? 'text-decoration:line-through;' : '' ?>">
                    <?= number_format((int)$r['qty']) ?>
                </td>
                <td style="text-align:right;font-weight:600;<?= $muted ? 'text-decoration:line-through;' : '' ?>">
                    <?= e(money((float)$r['total_amount'])) ?>
                </td>
                <td><span class="badge badge-info"><?= e(ucfirst(str_replace('_',' ', $r['method']))) ?></span></td>
                <td>
                    <?php if ($isReversed): ?>
                        <span class="badge badge-rejected">Reversed</span>
                    <?php elseif ($isReversal): ?>
                        <span class="badge badge-pending">Reversal</span>
                    <?php else: ?>
                        <span class="badge badge-approved">Posted</span>
                    <?php endif; ?>
                </td>
                <td class="muted" style="font-size:.82rem;"><?= e($r['recorded_by_name'] ?: 'System') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="8" class="center muted" style="padding:48px;">
                <div style="font-size:2rem;margin-bottom:8px;">📭</div>
                <div style="font-weight:600;color:var(--text);">No purchases found</div>
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