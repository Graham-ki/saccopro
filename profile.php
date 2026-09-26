<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
$user = require_login();

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    // ---- Update profile ----
    if ($action === 'update_profile') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));

        if ($fullName === '') $errors[] = 'Full name is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address is invalid.';
        }

        if (!$errors) {
            // Uniqueness check
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
            $chk->execute([$email, $user['id']]);
            if ($chk->fetch()) {
                $errors[] = 'That email is already in use.';
            } else {
                $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?")
                    ->execute([$fullName, $email ?: null, $user['id']]);

                audit_log($pdo, (int)$user['id'], 'profile.update', 'user', (int)$user['id'], 'Updated name/email');

                flash('success', 'Profile updated.');
                redirect('/scms/profile.php');
            }
        }
    }

    // ---- Change password ----
    if ($action === 'change_password') {
        $current  = (string)($_POST['current_password'] ?? '');
        $new      = (string)($_POST['new_password'] ?? '');
        $confirm  = (string)($_POST['confirm_password'] ?? '');

        if ($current === '') $errors[] = 'Enter your current password.';
        if (strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';
        if ($new !== $confirm) $errors[] = 'New passwords do not match.';

        if (!$errors) {
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $hash = (string)$stmt->fetchColumn();

            if (!password_verify($current, $hash)) {
                $errors[] = 'Current password is incorrect.';
            } else {
                $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);

                audit_log($pdo, (int)$user['id'], 'profile.password', 'user', (int)$user['id'], 'Password changed');

                flash('success', 'Password changed successfully.');
                redirect('/scms/profile.php');
            }
        }
    }
}

$pageTitle = 'My Profile';
require_once __DIR__ . '/includes/header.php';

$initial = strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1));

// Recent activity by this user
$stmt = $pdo->prepare("
    SELECT * FROM activity_log
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute([$user['id']]);
$myActivity = $stmt->fetchAll();
?>
<style>
.dash-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); margin-bottom:14px; }
.field input, .field select {
    padding:11px 12px; border:1.5px solid var(--border); border-radius:8px;
    background:var(--surface); color:var(--text); font:inherit; font-size:.95rem;
    width:100%;
}
.field input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,.12); }
.field input:disabled { opacity:.6; cursor:not-allowed; }
@media (max-width:900px) { .dash-grid { grid-template-columns:1fr; } }
</style>
<div class="page-head">
    <div style="display:flex;align-items:center;gap:16px;">
        <div class="avatar" style="width:64px;height:64px;font-size:1.4rem;border-radius:16px;"><?= e($initial) ?></div>
        <div>
            <h1 style="margin-bottom:2px;"><?= e($user['full_name']) ?></h1>
            <p style="margin:0;">
                <span class="badge badge-<?= e($user['status']) ?>"><?= e($user['status']) ?></span>
                &nbsp;·&nbsp; <?= e(ucfirst($user['role'])) ?>
                &nbsp;·&nbsp; @<?= e($user['username']) ?>
            </p>
        </div>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-error">
        <strong>Please fix:</strong>
        <ul style="margin-top:8px;">
            <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="dash-grid">

    <!-- Profile form -->
    <div class="card animate-fade-up">
        <h3 class="card-title" style="margin-bottom:16px;">Profile details</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_profile">

            <label class="field"><span>Full name *</span>
                <input type="text" name="full_name" value="<?= e($user['full_name']) ?>" required>
            </label>

            <label class="field"><span>Email</span>
                <input type="email" name="email" value="<?= e($user['email']) ?>">
            </label>

            <label class="field"><span>Username</span>
                <input type="text" value="<?= e($user['username']) ?>" disabled>
                <small class="muted" style="font-size:.75rem;">Username cannot be changed.</small>
            </label>

            <div style="margin-top:20px;text-align:right;">
                <button class="btn btn-primary">Save profile</button>
            </div>
        </form>
    </div>

    <!-- Change password -->
    <div class="card animate-fade-up delay-1">
        <h3 class="card-title" style="margin-bottom:16px;">Change password</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="change_password">

            <label class="field"><span>Current password *</span>
                <input type="password" name="current_password" required>
            </label>

            <label class="field"><span>New password *</span>
                <input type="password" name="new_password" minlength="8" required>
                <small class="muted" style="font-size:.75rem;">At least 8 characters.</small>
            </label>

            <label class="field"><span>Confirm new password *</span>
                <input type="password" name="confirm_password" minlength="8" required>
            </label>

            <div style="margin-top:20px;text-align:right;">
                <button class="btn btn-primary">Update password</button>
            </div>
        </form>
    </div>
</div>

<!-- My recent activity -->
<div class="card animate-fade-up delay-2" style="margin-top:18px;">
    <div class="card-title" style="margin-bottom:14px;">My recent activity</div>
    <?php if ($myActivity): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>When</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr>
                </thead>
                <tbody>
                <?php foreach ($myActivity as $a): ?>
                    <tr>
                        <td class="muted" style="font-size:.82rem;"><?= e(date('M j, Y g:ia', strtotime($a['created_at']))) ?></td>
                        <td><code style="font-size:.78rem;"><?= e($a['action']) ?></code></td>
                        <td class="muted" style="font-size:.82rem;">
                            <?= e($a['entity'] ?: '—') ?><?= $a['entity_id'] ? ' #' . (int)$a['entity_id'] : '' ?>
                        </td>
                        <td style="font-size:.85rem;"><?= e($a['details'] ?: '—') ?></td>
                        <td class="muted" style="font-size:.78rem;"><?= e($a['ip'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="center muted" style="padding:24px;">No activity recorded yet.</div>
    <?php endif; ?>
</div>



<?php require_once __DIR__ . '/includes/footer.php'; ?>