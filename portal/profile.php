<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_member();
$member = current_member();
$memberId = (int)$member['id'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'contact') {
        $phone   = trim((string)($_POST['phone'] ?? ''));
        $email   = trim((string)($_POST['email'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));

        if ($phone === '') $errors[] = 'Phone is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is invalid.';

        if (!$errors) {
            $pdo->prepare("UPDATE members SET phone=?, email=?, address=? WHERE id=?")
                ->execute([$phone, $email ?: null, $address ?: null, $memberId]);

            audit_log($pdo, (int)$user['id'], 'portal.profile_update', 'member', $memberId, 'Updated contact info');
            flash('success', 'Contact information updated.');
            redirect('/scms/portal/profile.php');
        }
    }

    if ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if ($current === '') $errors[] = 'Enter current password.';
        if (strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';
        if ($new !== $confirm) $errors[] = 'New passwords do not match.';

        if (!$errors) {
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([(int)$user['id']]);
            $hash = (string)$stmt->fetchColumn();

            if (!password_verify($current, $hash)) {
                $errors[] = 'Current password is incorrect.';
            } else {
                $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                    ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
                audit_log($pdo, (int)$user['id'], 'portal.password_change', 'user', (int)$user['id'], 'Password changed');
                flash('success', 'Password updated.');
                redirect('/scms/portal/profile.php');
            }
        }
    }
}

$initial = strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1));

$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.dash-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); margin-bottom:14px; }
.field input { padding:11px 12px; border:1.5px solid var(--border); border-radius:8px; background:var(--surface); color:var(--text); font:inherit; font-size:.95rem; }
.field input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,.12); }
@media (max-width:900px) { .dash-grid { grid-template-columns:1fr; } }
</style>
<div class="page-head">
    <div style="display:flex;align-items:center;gap:16px;">
        <div class="avatar" style="width:56px;height:56px;font-size:1.2rem;border-radius:14px;"><?= e($initial) ?></div>
        <div>
            <h1 style="margin-bottom:2px;"><?= e($member['first_name'] . ' ' . $member['last_name']) ?></h1>
            <p style="margin:0;">
                <code><?= e($member['member_no']) ?></code>
                &nbsp;·&nbsp;
                <span class="badge badge-<?= e($member['status']) ?>"><?= e($member['status']) ?></span>
                &nbsp;·&nbsp; joined <?= e(date('M j, Y', strtotime($member['join_date']))) ?>
            </p>
        </div>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <ul style="margin:0;padding-left:18px;">
            <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<div class="dash-grid">

    <div class="card animate-fade-up">
        <h3 class="card-title" style="margin-bottom:14px;">Contact information</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="contact">

            <label class="field"><span>Phone *</span>
                <input type="text" name="phone" value="<?= e($member['phone']) ?>" required>
            </label>
            <label class="field"><span>Email</span>
                <input type="email" name="email" value="<?= e($member['email']) ?>">
            </label>
            <label class="field"><span>Address</span>
                <input type="text" name="address" value="<?= e($member['address']) ?>">
            </label>

            <div style="display:flex;justify-content:flex-end;margin-top:14px;">
                <button class="btn btn-primary">Save changes</button>
            </div>
        </form>
    </div>

    <div class="card animate-fade-up delay-1">
        <h3 class="card-title" style="margin-bottom:14px;">Security</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="password">

            <label class="field"><span>Current password *</span>
                <input type="password" name="current_password" required>
            </label>
            <label class="field"><span>New password *</span>
                <input type="password" name="new_password" required minlength="8">
            </label>
            <label class="field"><span>Confirm new password *</span>
                <input type="password" name="confirm_password" required minlength="8">
            </label>

            <div style="display:flex;justify-content:flex-end;margin-top:14px;">
                <button class="btn btn-primary">Change password</button>
            </div>
        </form>
    </div>
</div>

<div class="card animate-fade-up delay-2" style="margin-top:18px;">
    <h3 class="card-title" style="margin-bottom:14px;">Account details</h3>
    <p class="muted" style="font-size:.85rem;margin-bottom:14px;">
        Only an admin can change these. Contact the office if anything needs updating.
    </p>
    <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px 24px;font-size:.9rem;">
        <div><dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Member number</dt><dd style="margin:4px 0 0;font-weight:600;"><code><?= e($member['member_no']) ?></code></dd></div>
        <div><dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Savings account</dt><dd style="margin:4px 0 0;font-weight:600;"><code><?= e($member['account_no'] ?: '—') ?></code></dd></div>
        <div><dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Gender</dt><dd style="margin:4px 0 0;font-weight:600;"><?= e($member['gender'] ? ucfirst($member['gender']) : '—') ?></dd></div>
        <div><dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Date of birth</dt><dd style="margin:4px 0 0;font-weight:600;"><?= $member['dob'] ? e(date('M j, Y', strtotime($member['dob']))) : '—' ?></dd></div>
        <div><dt class="muted" style="font-size:.75rem;text-transform:uppercase;">ID number</dt><dd style="margin:4px 0 0;font-weight:600;"><?= e($member['id_number'] ?: '—') ?></dd></div>
    </dl>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>