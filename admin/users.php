<?php
declare(strict_types=1);

// ---- Bootstrap (no output) ----
require_once __DIR__ . '/../includes/auth.php';
$user = require_admin();

// ---- Handle POST before any HTML ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $targetId = (int)($_POST['user_id'] ?? 0);
    $action   = (string)($_POST['action'] ?? '');
    $allowed  = ['approved', 'rejected', 'suspended'];

    if ($targetId && in_array($action, $allowed, true) && $targetId !== (int)$_SESSION['user_id']) {
        $pdo->prepare("UPDATE users SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?")
            ->execute([$action, (int)$_SESSION['user_id'], $targetId]);
            audit_log($pdo, (int)$user['id'], 'user.status_change', 'user', $targetId, "Set status to {$action}");
        flash('success', "User #{$targetId} set to '{$action}'.");
    }

    // Preserve the current tab (validated)
    $back = $_GET['status'] ?? 'pending';
    $validFilters = ['pending', 'approved', 'rejected', 'suspended', 'all'];
    if (!in_array($back, $validFilters, true)) $back = 'pending';

    redirect('/scms/admin/users.php?status=' . urlencode($back));   // exits — clean
}

// ---- Data ----
$filter = $_GET['status'] ?? 'pending';
$validFilters = ['pending', 'approved', 'rejected', 'suspended', 'all'];
if (!in_array($filter, $validFilters, true)) $filter = 'pending';

if ($filter === 'all') {
    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
} else {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE status = ? ORDER BY created_at DESC");
    $stmt->execute([$filter]);
}
$users = $stmt->fetchAll();

// ---- Render ----
$pageTitle = 'User Management';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>User Management</h1>
        <p>Approve, reject, or suspend user accounts.</p>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><?= e($msg) ?></div>
<?php endif; ?>

<nav class="tabs">
    <?php foreach ($validFilters as $f): ?>
        <a href="?status=<?= e($f) ?>" class="tab <?= $f === $filter ? 'active' : '' ?>">
            <?= e(ucfirst($f)) ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>#</th><th>Name</th><th>Username</th><th>Email</th>
                <th>Role</th><th>Status</th><th>Registered</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><strong><?= e($u['full_name']) ?></strong></td>
                <td><?= e($u['username']) ?></td>
                <td class="muted"><?= e($u['email']) ?></td>
                <td><?= e(ucfirst($u['role'])) ?></td>
                <td><span class="badge badge-<?= e($u['status']) ?>"><?= e($u['status']) ?></span></td>
                <td class="muted"><?= e(date('M j, Y', strtotime($u['created_at']))) ?></td>
                <td>
                    <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                        <div class="flex gap-2">
                        <?php foreach (['approved','rejected','suspended'] as $act): ?>
                            <?php if ($u['status'] !== $act): ?>
                                <form method="post" class="inline">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <input type="hidden" name="action" value="<?= e($act) ?>">
                                    <button class="btn btn-sm btn-<?= $act === 'approved' ? 'success' : ($act === 'rejected' ? 'danger' : 'warning') ?>">
                                        <?= e(ucfirst($act)) ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <em class="muted">(you)</em>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?>
            <tr><td colspan="8" class="center muted" style="padding:40px;">
                <div style="font-size:2rem;margin-bottom:8px;">👤</div>
                <div style="font-weight:600;color:var(--text);">No users in this category.</div>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>