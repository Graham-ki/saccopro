<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_admin();

$status = $_GET['status'] ?? 'pending';
$type   = $_GET['type']   ?? 'all';
$validStatus = ['pending','approved','rejected','cancelled','all'];
$validTypes  = ['all','deposit','withdrawal','loan','share_purchase'];
if (!in_array($status, $validStatus, true)) $status = 'pending';
if (!in_array($type, $validTypes, true)) $type = 'all';

$where = [];
$params = [];

if ($status !== 'all') { $where[] = "r.status = :status"; $params[':status'] = $status; }
if ($type !== 'all')   { $where[] = "r.type = :type";     $params[':type']   = $type; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT r.*, m.id AS member_id, m.first_name, m.last_name, m.member_no,
           u.full_name AS decided_by_name
    FROM member_requests r
    JOIN members m ON m.id = r.member_id
    LEFT JOIN users u ON u.id = r.decided_by
    $whereSql
    ORDER BY r.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Counts
$counts = $pdo->query("
    SELECT
        SUM(status='pending')  AS pending,
        SUM(status='approved') AS approved,
        SUM(status='rejected') AS rejected,
        SUM(status='cancelled') AS cancelled
    FROM member_requests
")->fetch();

$pageTitle = 'Member Requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Member Requests</h1>
        <p>Review and approve member-submitted requests.</p>
    </div>
    <div class="page-head-actions">
        <a href="link-member.php" class="btn btn-outline">Portal access</a>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><?= e($msg) ?></div>
<?php endif; ?>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:18px;">
    <div class="stat animate-fade-up">
        <div class="stat-label">Pending</div>
        <div class="stat-value" style="color:<?= (int)$counts['pending'] > 0 ? 'var(--warning)' : 'inherit' ?>;">
            <?= (int)$counts['pending'] ?>
        </div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-label">Approved</div>
        <div class="stat-value"><?= (int)$counts['approved'] ?></div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-label">Rejected</div>
        <div class="stat-value"><?= (int)$counts['rejected'] ?></div>
    </div>
    <div class="stat animate-fade-up delay-3">
        <div class="stat-label">Cancelled</div>
        <div class="stat-value"><?= (int)$counts['cancelled'] ?></div>
    </div>
</div>

<nav class="tabs" style="margin-bottom:18px;">
    <?php foreach ($validStatus as $s): ?>
        <a href="?status=<?= e($s) ?>&type=<?= e($type) ?>"
           class="tab <?= $s === $status ? 'active' : '' ?>">
            <?= e(ucfirst($s)) ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>When</th>
                <th>Member</th>
                <th>Type</th>
                <th style="text-align:right;">Amount / Qty</th>
                <th>Notes</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <td class="muted" style="font-size:.82rem;white-space:nowrap;">
                    <?= e(date('M j, g:ia', strtotime($r['created_at']))) ?>
                </td>
                <td>
                    <a href="/scms/members/view.php?id=<?= (int)$r['member_id'] ?>" style="font-weight:600;">
                        <?= e($r['first_name'] . ' ' . $r['last_name']) ?>
                    </a>
                    <div class="muted" style="font-size:.76rem;"><code><?= e($r['member_no']) ?></code></div>
                </td>
                <td>
                    <span class="badge badge-<?=
                        $r['type'] === 'deposit' ? 'approved' :
                        ($r['type'] === 'withdrawal' ? 'rejected' :
                        ($r['type'] === 'loan' ? 'info' : 'pending')) ?>">
                        <?= e(ucfirst(str_replace('_',' ', $r['type']))) ?>
                    </span>
                </td>
                <td style="text-align:right;font-weight:600;">
                    <?php if ($r['type'] === 'share_purchase'): ?>
                        <?= (int)$r['qty'] ?> shares
                    <?php else: ?>
                        <?= e(money((float)$r['amount'])) ?>
                    <?php endif; ?>
                </td>
                <td class="muted" style="font-size:.82rem;max-width:200px;">
                    <?= e($r['notes'] ?: ($r['purpose'] ?: '—')) ?>
                </td>
                <td>
                    <span class="badge badge-<?=
                        $r['status'] === 'approved' ? 'approved' :
                        ($r['status'] === 'rejected' ? 'rejected' :
                        ($r['status'] === 'cancelled' ? 'suspended' : 'pending')) ?>">
                        <?= e($r['status']) ?>
                    </span>
                </td>
                <td style="text-align:right;">
                    <a href="request-view.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm">
                        <?= $r['status'] === 'pending' ? 'Review' : 'View' ?>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$requests): ?>
            <tr><td colspan="7" class="center muted" style="padding:48px;">
                <div style="font-size:2rem;margin-bottom:8px;">📭</div>
                <div style="font-weight:600;color:var(--text);">No requests</div>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>