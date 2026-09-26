<?php
$pageTitle = 'Loans';
require_once __DIR__ . '/../includes/header.php';

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'all';
$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));

$validStatus = ['all','active','completed','defaulted','pending','cancelled'];
if (!in_array($status, $validStatus, true)) $status = 'all';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_no LIKE ? OR l.loan_no LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
if ($status !== 'all') { $where[] = "l.status = ?"; $params[] = $status; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$base = "FROM loans l JOIN members m ON m.id = l.member_id $whereSql";

$countStmt = $pdo->prepare("SELECT COUNT(*) $base");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pg = paginate($total, $perPage, $page);

$stmt = $pdo->prepare("
    SELECT l.*, m.first_name, m.last_name, m.member_no
    $base
    ORDER BY l.created_at DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
);
$stmt->execute($params);
$loans = $stmt->fetchAll();

// Global KPIs
$kpi = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN status='active' THEN balance ELSE 0 END), 0) AS active_balance,
        COALESCE(SUM(CASE WHEN status='active' THEN principal ELSE 0 END), 0) AS active_principal,
        COUNT(CASE WHEN status='active' THEN 1 END) AS active_count,
        COUNT(CASE WHEN status='completed' THEN 1 END) AS completed_count,
        COALESCE(SUM(CASE WHEN status='active' AND maturity_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_count
    FROM loans
")->fetch();

$dueToday = $pdo->query("
    SELECT COUNT(*) FROM loan_schedules ls
    JOIN loans l ON l.id = ls.loan_id
    WHERE ls.status IN ('pending','partial','overdue')
      AND ls.due_date = CURDATE()
      AND l.status = 'active'
")->fetchColumn();
?>

<div class="page-head">
    <div>
        <h1>Loans</h1>
        <p>Issue loans, track schedules, and record repayments.</p>
    </div>
    <div class="page-head-actions">
        <a href="repayments.php" class="btn btn-ghost">Repayments</a>
        <a href="create.php" class="btn btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" d="M12 4v16m8-8H4"/>
            </svg>
            Issue loan
        </a>
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
        <div class="stat-head"><div><div class="stat-label">Active loans</div></div><div class="stat-icon">📄</div></div>
        <div class="stat-value"><?= (int)$kpi['active_count'] ?></div>
        <div class="stat-trend muted"><?= (int)$kpi['completed_count'] ?> completed</div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-head"><div><div class="stat-label">Principal out</div></div><div class="stat-icon">💵</div></div>
        <div class="stat-value"><?= e(money($kpi['active_principal'])) ?></div>
        <div class="stat-trend muted">Across active loans</div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-head"><div><div class="stat-label">Outstanding balance</div></div><div class="stat-icon">📊</div></div>
        <div class="stat-value"><?= e(money($kpi['active_balance'])) ?></div>
        <div class="stat-trend muted">Principal + interest</div>
    </div>
    <div class="stat animate-fade-up delay-3">
        <div class="stat-head"><div><div class="stat-label">Due today / overdue</div></div><div class="stat-icon">⏰</div></div>
        <div class="stat-value"><?= (int)$dueToday ?> / <?= (int)$kpi['overdue_count'] ?></div>
        <div class="stat-trend <?= (int)$kpi['overdue_count'] ? 'down' : 'muted' ?>">
            <?= (int)$kpi['overdue_count'] ? 'Needs attention' : 'All on track' ?>
        </div>
    </div>
</div>

<form method="get" class="card" style="padding:14px 18px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:18px;">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search loan no, member…"
           style="flex:1;min-width:220px;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    <select name="status" onchange="this.form.submit()"
            style="padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
        <option value="all"       <?= $status === 'all' ? 'selected' : '' ?>>All statuses</option>
        <option value="active"    <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
        <option value="defaulted" <?= $status === 'defaulted' ? 'selected' : '' ?>>Defaulted</option>
        <option value="pending"   <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
        <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
    </select>
    <button class="btn btn-outline btn-sm" type="submit">Apply</button>
    <?php if ($search || $status !== 'all'): ?><a href="index.php" class="btn btn-ghost btn-sm">Reset</a><?php endif; ?>
</form>

<div class="table-wrap animate-fade-in">
    <table class="table">
        <thead>
            <tr>
                <th>Loan</th>
                <th>Member</th>
                <th style="text-align:right;">Principal</th>
                <th>Rate / Term</th>
                <th style="text-align:right;">Balance</th>
                <th>Progress</th>
                <th>Maturity</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($loans as $l): ?>
            <?php
                $paid  = (float)$l['amount_paid'];
                $total = (float)$l['total_payable'];
                $pct   = $total > 0 ? round($paid / $total * 100) : 0;
                $overdue = $l['status'] === 'active' && strtotime($l['maturity_date']) < time();
            ?>
            <tr>
                <td><code style="font-size:.8rem;"><?= e($l['loan_no']) ?></code></td>
                <td>
                    <a href="/scms/members/view.php?id=<?= (int)$l['member_id'] ?>" style="font-weight:600;">
                        <?= e($l['first_name'] . ' ' . $l['last_name']) ?>
                    </a>
                    <div class="muted" style="font-size:.76rem;"><code><?= e($l['member_no']) ?></code></div>
                </td>
                <td style="text-align:right;"><?= e(money($l['principal'])) ?></td>
                <td>
                    <div style="font-size:.85rem;"><?= e(rtrim(rtrim(number_format((float)$l['interest_rate'], 2), '0'), '.')) ?>%/mo · <?= (int)$l['term_months'] ?>mo</div>
                    <div class="muted" style="font-size:.75rem;text-transform:capitalize;"><?= e($l['interest_method']) ?></div>
                </td>
                <td style="text-align:right;font-weight:600;"><?= e(money($l['balance'])) ?></td>
                <td>
                    <div style="min-width:100px;">
                        <div style="display:flex;justify-content:space-between;font-size:.75rem;color:var(--text-3);margin-bottom:3px;">
                            <span><?= $pct ?>%</span>
                        </div>
                        <div style="height:6px;background:var(--border-2);border-radius:99px;overflow:hidden;">
                            <div style="width:<?= $pct ?>%;height:100%;background:linear-gradient(90deg,var(--primary),var(--accent));transition:width .4s;"></div>
                        </div>
                    </div>
                </td>
                <td style="white-space:nowrap;">
                    <?= e(date('M j, Y', strtotime($l['maturity_date']))) ?>
                    <?php if ($overdue): ?>
                        <div><span class="badge badge-rejected" style="font-size:.65rem;">Overdue</span></div>
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-<?= e($l['status']) === 'active' ? 'info' : ($l['status'] === 'completed' ? 'approved' : 'rejected') ?>"><?= e($l['status']) ?></span></td>
                <td style="text-align:right;">
                    <div style="display:inline-flex;gap:6px;">
                        <a href="view.php?id=<?= (int)$l['id'] ?>" class="btn btn-ghost btn-sm">View</a>
                        <?php if ($l['status'] === 'active'): ?>
                            <a href="repay.php?loan_id=<?= (int)$l['id'] ?>" class="btn btn-success btn-sm">Repay</a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$loans): ?>
            <tr><td colspan="9" class="center muted" style="padding:48px;">
                <div style="font-size:2rem;margin-bottom:8px;">📄</div>
                <div style="font-weight:600;color:var(--text);margin-bottom:4px;">No loans found</div>
                <div style="font-size:.85rem;">Issue the first loan to get started.</div>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($pg['pages'] > 1): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;flex-wrap:wrap;gap:12px;">
        <div class="muted" style="font-size:.85rem;">
            Showing <?= $pg['offset'] + 1 ?>–<?= min($pg['offset'] + $pg['per_page'], $pg['total']) ?>
            of <?= number_format($pg['total']) ?> loans
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