<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_admin();

// Filters
$search = trim((string)($_GET['q'] ?? ''));
$userId = (int)($_GET['user_id'] ?? 0);
$action = trim((string)($_GET['action'] ?? ''));
$entity = trim((string)($_GET['entity'] ?? ''));
$from   = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to     = $_GET['to']   ?? date('Y-m-d');

// Validate
$valid = fn(string $d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$valid($from)) $from = date('Y-m-d', strtotime('-30 days'));
if (!$valid($to))   $to   = date('Y-m-d');
if (strtotime($from) > strtotime($to)) [$from, $to] = [$to, $from];

$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));

$where = [];
$params = [];

$where[] = "a.created_at >= :from";
$params[':from'] = $from . ' 00:00:00';
$where[] = "a.created_at <= :to";
$params[':to'] = $to . ' 23:59:59';

if ($search !== '') {
    $where[] = "(a.details LIKE :search OR a.action LIKE :search2 OR u.full_name LIKE :search3)";
    $params[':search']  = "%$search%";
    $params[':search2'] = "%$search%";
    $params[':search3'] = "%$search%";
}
if ($userId) {
    $where[] = "a.user_id = :uid";
    $params[':uid'] = $userId;
}
if ($action !== '') {
    $where[] = "a.action = :act";
    $params[':act'] = $action;
}
if ($entity !== '') {
    $where[] = "a.entity = :ent";
    $params[':ent'] = $entity;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);
$base = "FROM activity_log a
         LEFT JOIN users u ON u.id = a.user_id
         $whereSql";

$cnt = $pdo->prepare("SELECT COUNT(*) $base");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pg = paginate($total, $perPage, $page);

$stmt = $pdo->prepare("
    SELECT a.*, u.full_name AS user_name, u.username
    $base
    ORDER BY a.created_at DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Distinct actions / entities for filters
$actions = $pdo->query("SELECT DISTINCT action FROM activity_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$entities = $pdo->query("SELECT DISTINCT entity FROM activity_log WHERE entity IS NOT NULL ORDER BY entity")->fetchAll(PDO::FETCH_COLUMN);

$users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name")->fetchAll();

$pageTitle = 'Audit Log';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Audit Log</h1>
        <p><?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?> found.</p>
    </div>
    <div class="page-head-actions">
        <a href="/scms/admin/export-audit.php?<?= e(http_build_query($_GET)) ?>" class="btn btn-outline">⬇ CSV</a>
    </div>
</div>

<form method="get" class="card" style="padding:14px 18px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;">
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);flex:1;min-width:200px;">
        Search
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Details, action, user…"
               style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        User
        <select name="user_id" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
            <option value="0">All</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>>
                    <?= e($u['full_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        Action
        <select name="action" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
            <option value="">All</option>
            <?php foreach ($actions as $a): ?>
                <option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e($a) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        Entity
        <select name="entity" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
            <option value="">All</option>
            <?php foreach ($entities as $en): ?>
                <option value="<?= e($en) ?>" <?= $entity === $en ? 'selected' : '' ?>><?= e($en) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        From
        <input type="date" name="from" value="<?= e($from) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        To
        <input type="date" name="to" value="<?= e($to) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </label>
    <button class="btn btn-outline btn-sm" type="submit">Apply</button>
    <a href="audit.php" class="btn btn-ghost btn-sm">Reset</a>
</form>

<div class="table-wrap animate-fade-in">
    <table class="table">
        <thead>
            <tr>
                <th>When</th>
                <th>User</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Details</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="muted" style="font-size:.82rem;white-space:nowrap;">
                    <?= e(date('M j, Y g:ia', strtotime($r['created_at']))) ?>
                </td>
                <td>
                    <?php if ($r['user_name']): ?>
                        <div style="font-weight:600;"><?= e($r['user_name']) ?></div>
                        <div class="muted" style="font-size:.76rem;">@<?= e($r['username']) ?></div>
                    <?php else: ?>
                        <span class="muted">System</span>
                    <?php endif; ?>
                </td>
                <td><code style="font-size:.78rem;"><?= e($r['action']) ?></code></td>
                <td class="muted" style="font-size:.82rem;">
                    <?= e($r['entity'] ?: '—') ?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?>
                </td>
                <td style="font-size:.85rem;"><?= e($r['details'] ?: '—') ?></td>
                <td class="muted" style="font-size:.78rem;"><?= e($r['ip'] ?: '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="center muted" style="padding:48px;">
                <div style="font-size:2rem;margin-bottom:8px;">📋</div>
                <div style="font-weight:600;color:var(--text);">No activity in this range</div>
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