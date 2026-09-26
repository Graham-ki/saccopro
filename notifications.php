<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
$user = require_login();
require_once __DIR__ . '/includes/notifications.php';

// ---- Handle POST (dismiss / dismiss-all) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'dismiss' && !empty($_POST['key'])) {
        dismiss_notification($pdo, (int)$user['id'], (string)$_POST['key']);
        flash('success', 'Notification dismissed.');
        redirect('/scms/notifications.php');
    }

    if ($action === 'dismiss_all') {
        $data = build_notifications($pdo, $user);
        $keys = array_column($data['items'], 'key');
        $n = dismiss_notifications($pdo, (int)$user['id'], $keys);
        flash('success', "Dismissed {$n} notification(s).");
        redirect('/scms/notifications.php');
    }
}

$data  = build_notifications($pdo, $user);
$items = $data['items'];

$pageTitle = 'Notifications';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.notif-row {
    display:flex; align-items:center; gap:14px;
    padding:14px 16px;
    border-radius:10px;
    transition:background .2s;
}
.notif-row + .notif-row { border-top:1px solid var(--border-2); }
.notif-row:hover { background:var(--surface-2); }
.notif-row.unread { background:var(--primary-l); }
.notif-row .notif-icon {
    width:44px; height:44px;
    border-radius:12px;
    display:grid; place-items:center;
    background:var(--surface);
    border:1px solid var(--border-2);
    font-size:1.2rem;
    flex-shrink:0;
    text-decoration:none;
    color:inherit;
}
.notif-dismiss { margin:0; }
.notif-dismiss-btn {
    width:32px; height:32px;
    border-radius:8px;
    border:none;
    background:transparent;
    color:var(--text-3);
    cursor:pointer;
    font-size:1rem;
    display:grid; place-items:center;
    transition:all .2s;
}
.notif-dismiss-btn:hover {
    background:rgba(239,68,68,.12);
    color:var(--danger);
}
</style>
<div class="page-head">
    <div>
        <h1>Notifications</h1>
        <p><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?> needing attention.</p>
    </div>
    <?php if ($items): ?>
        <div class="page-head-actions">
            <form method="post" onsubmit="return confirm('Dismiss all notifications? You can still see them later if the underlying event stays unresolved.');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="dismiss_all">
                <button class="btn btn-outline btn-sm">Dismiss all</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<?php if (!$items): ?>
    <div class="card center" style="padding:60px 24px;">
        <div style="font-size:3rem;margin-bottom:12px;">🎉</div>
        <div style="font-weight:700;font-size:1.1rem;">You're all caught up</div>
        <div class="muted" style="margin-top:6px;">No new notifications right now.</div>
    </div>
<?php else: ?>
    <div class="card" style="padding:8px;">
        <?php foreach ($items as $n): ?>
            <div class="notif-row <?= !empty($n['unread']) ? 'unread' : '' ?>">
                <a href="<?= e($n['url'] ?? '#') ?>" class="notif-icon" title="Open">
                    <?= e($n['icon']) ?>
                </a>
                <div style="flex:1;min-width:0;">
                    <a href="/scms/notification-view.php?key=<?= urlencode($n['key']) ?>"
                       style="text-decoration:none;color:inherit;display:block;">
                        <div style="font-weight:600;"><?= e($n['title']) ?></div>
                        <div class="muted" style="font-size:.85rem;"><?= e($n['text']) ?></div>
                    </a>
                </div>
                <div class="muted" style="font-size:.78rem;white-space:nowrap;"><?= e($n['time']) ?></div>
                <form method="post" class="notif-dismiss"
                      onsubmit="return confirm('Dismiss this notification?');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="dismiss">
                    <input type="hidden" name="key" value="<?= e($n['key']) ?>">
                    <button class="notif-dismiss-btn" title="Dismiss" aria-label="Dismiss">✕</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>



<?php require_once __DIR__ . '/includes/footer.php'; ?>