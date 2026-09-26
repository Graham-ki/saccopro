<?php
$pageTitle = 'Members';
require_once __DIR__ . '/../includes/header.php';

// ---- Filters & pagination ----
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'all';
$sort   = $_GET['sort']   ?? 'newest';
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));

$validStatus = ['all', 'active', 'inactive'];
if (!in_array($status, $validStatus, true)) $status = 'all';

$sortMap = [
    'newest' => 'm.created_at DESC',
    'oldest' => 'm.created_at ASC',
    'name'   => 'm.last_name ASC, m.first_name ASC',
    'member' => 'm.member_no ASC',
];
$orderBy = $sortMap[$sort] ?? $sortMap['newest'];

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_no LIKE ? OR m.phone LIKE ? OR m.email LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like, $like);
}
if ($status !== 'all') {
    $where[] = "m.status = ?";
    $params[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM members m $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$pg = paginate($total, $perPage, $page);

// Fetch
$sql = "SELECT m.*, sa.account_no, sa.balance,
               (SELECT COUNT(*) FROM loans l WHERE l.member_id = m.id AND l.status = 'active') AS active_loans
        FROM members m
        LEFT JOIN savings_accounts sa ON sa.member_id = m.id
        $whereSql
        ORDER BY $orderBy
        LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

// Summary stats (unfiltered)
$totals = $pdo->query("SELECT
    COUNT(*) AS total_members,
    SUM(status='active') AS active_members,
    SUM(status='inactive') AS inactive_members
    FROM members")->fetch();

$savingsTotal = (float)$pdo->query("SELECT COALESCE(SUM(balance),0) FROM savings_accounts")->fetchColumn();
?>

<div class="page-head">
    <div>
        <h1>Members</h1>
        <p>Manage everyone registered in your group.</p>
    </div>
    <div class="page-head-actions">
        <a href="/scms/reports/index.php" class="btn btn-ghost">Export</a>
        <a href="/scms/members/create.php" class="btn btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" d="M12 4v16m8-8H4"/>
            </svg>
            Add member
        </a>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><?= e($msg) ?></div>
<?php endif; ?>

<!-- Quick stats -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
    <div class="stat animate-fade-up">
        <div class="stat-head">
            <div><div class="stat-label">Total members</div></div>
            <div class="stat-icon">👥</div>
        </div>
        <div class="stat-value"><?= number_format((int)$totals['total_members']) ?></div>
        <div class="stat-trend muted"><?= (int)$totals['active_members'] ?> active</div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-head">
            <div><div class="stat-label">Inactive</div></div>
            <div class="stat-icon">💤</div>
        </div>
        <div class="stat-value"><?= number_format((int)$totals['inactive_members']) ?></div>
        <div class="stat-trend muted">Not currently contributing</div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-head">
            <div><div class="stat-label">Total savings held</div></div>
            <div class="stat-icon">💰</div>
        </div>
        <div class="stat-value"><?= e(money($savingsTotal)) ?></div>
        <div class="stat-trend up">Across all accounts</div>
    </div>
</div>

<!-- Toolbar -->
<form method="get" class="card" style="padding:14px 18px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:18px;">
    <div style="flex:1;min-width:220px;position:relative;">
        <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-3);">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M21 21l-4.35-4.35"/>
            </svg>
        </span>
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search by name, number, phone…"
               style="width:100%;padding:9px 12px 9px 36px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </div>

    <select name="status" onchange="this.form.submit()"
            style="padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
        <option value="all"      <?= $status === 'all' ? 'selected' : '' ?>>All statuses</option>
        <option value="active"   <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>

    <select name="sort" onchange="this.form.submit()"
            style="padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
        <option value="name"   <?= $sort === 'name' ? 'selected' : '' ?>>Name (A–Z)</option>
        <option value="member" <?= $sort === 'member' ? 'selected' : '' ?>>Member no.</option>
    </select>

    <button class="btn btn-outline btn-sm" type="submit">Apply</button>
    <?php if ($search || $status !== 'all' || $sort !== 'newest'): ?>
        <a href="index.php" class="btn btn-ghost btn-sm">Reset</a>
    <?php endif; ?>
</form>

<!-- Table -->
<div class="table-wrap animate-fade-in">
    <table class="table">
        <thead>
            <tr>
                <th>Member</th>
                <th>Number</th>
                <th>Contact</th>
                <th>Account</th>
                <th style="text-align:right;">Savings</th>
                <th>Loans</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($members as $m): ?>
            <?php
                $initial = strtoupper(substr($m['first_name'], 0, 1) . substr($m['last_name'], 0, 1));
                $loans = (int)$m['active_loans'];
            ?>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div class="avatar" style="width:36px;height:36px;font-size:.8rem;"><?= e($initial) ?></div>
                        <div>
                            <div style="font-weight:600;"><?= e($m['first_name'] . ' ' . $m['last_name']) ?></div>
                            <div class="muted" style="font-size:.78rem;">Joined <?= e(date('M j, Y', strtotime($m['join_date']))) ?></div>
                        </div>
                    </div>
                </td>
                <td><code style="font-size:.82rem;"><?= e($m['member_no']) ?></code></td>
                <td>
                    <div style="font-size:.85rem;"><?= e($m['phone'] ?: '—') ?></div>
                    <div class="muted" style="font-size:.78rem;"><?= e($m['email'] ?: '') ?></div>
                </td>
                <td><code style="font-size:.82rem;"><?= e($m['account_no'] ?: '—') ?></code></td>
                <td style="text-align:right;font-weight:600;"><?= e(money($m['balance'] ?? 0)) ?></td>
                <td>
                    <?php if ($loans > 0): ?>
                        <span class="badge badge-info"><?= $loans ?> active</span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-<?= e($m['status']) ?>"><?= e($m['status']) ?></span></td>
                <td style="text-align:right;">
                    <div style="display:inline-flex;gap:6px;">
                        <a href="view.php?id=<?= (int)$m['id'] ?>" class="btn btn-ghost btn-sm" title="View">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </a>
                        <a href="edit.php?id=<?= (int)$m['id'] ?>" class="btn btn-ghost btn-sm" title="Edit">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                        </a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$members): ?>
            <tr>
                <td colspan="8" class="center muted" style="padding:48px;">
                    <div style="font-size:2rem;margin-bottom:8px;">🔍</div>
                    <div style="font-weight:600;color:var(--text);margin-bottom:4px;">No members found</div>
                    <div style="font-size:.85rem;">Try adjusting your search or filters.</div>
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($pg['pages'] > 1): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;flex-wrap:wrap;gap:12px;">
        <div class="muted" style="font-size:.85rem;">
            Showing <?= $pg['offset'] + 1 ?>–<?= min($pg['offset'] + $pg['per_page'], $pg['total']) ?>
            of <?= number_format($pg['total']) ?> members
        </div>
        <div style="display:flex;gap:6px;">
            <a href="<?= e(qs(['page' => max(1, $pg['current'] - 1)])) ?>"
               class="btn btn-outline btn-sm <?= $pg['current'] <= 1 ? 'disabled' : '' ?>"
               <?= $pg['current'] <= 1 ? 'style="pointer-events:none;opacity:.4;"' : '' ?>>← Prev</a>

            <?php for ($i = max(1, $pg['current'] - 2); $i <= min($pg['pages'], $pg['current'] + 2); $i++): ?>
                <a href="<?= e(qs(['page' => $i])) ?>"
                   class="btn btn-sm <?= $i === $pg['current'] ? 'btn-primary' : 'btn-outline' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <a href="<?= e(qs(['page' => min($pg['pages'], $pg['current'] + 1)])) ?>"
               class="btn btn-outline btn-sm <?= $pg['current'] >= $pg['pages'] ? 'disabled' : '' ?>"
               <?= $pg['current'] >= $pg['pages'] ? 'style="pointer-events:none;opacity:.4;"' : '' ?>>Next →</a>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '../../includes/footer.php'; ?>