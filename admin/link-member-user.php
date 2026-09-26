<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_admin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'link') {
        $userId   = (int)($_POST['user_id'] ?? 0);
        $memberId = (int)($_POST['member_id'] ?? 0);

        if (!$userId) $errors[] = 'Choose a user.';
        if (!$memberId) $errors[] = 'Choose a member.';

        if (!$errors) {
            // Ensure member isn't already linked
            $chk = $pdo->prepare("SELECT id FROM users WHERE member_id = ? AND id <> ? LIMIT 1");
            $chk->execute([$memberId, $userId]);
            if ($chk->fetch()) {
                $errors[] = 'That member is already linked to another user.';
            } else {
                $pdo->prepare("UPDATE users SET member_id = ?, role = 'member' WHERE id = ?")
                    ->execute([$memberId, $userId]);

                audit_log($pdo, (int)$user['id'], 'user.link_member', 'user', $userId,
                    "Linked user #$userId to member #$memberId");

                flash('success', 'User linked to member. They can now access the member portal.');
                redirect('/scms/admin/link-member.php');
            }
        }
    }

    if ($action === 'unlink') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId) {
            $pdo->prepare("UPDATE users SET member_id = NULL WHERE id = ?")->execute([$userId]);
            audit_log($pdo, (int)$user['id'], 'user.unlink_member', 'user', $userId,
                "Unlinked member from user #$userId");
            flash('success', 'Member unlinked.');
            redirect('/scms/admin/link-member.php');
        }
    }

    if ($action === 'invite') {
        // Create a user account for a member and email/print the credentials
        $memberId = (int)($_POST['member_id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = trim((string)($_POST['password'] ?? ''));

        if (!$memberId) $errors[] = 'Choose a member.';
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username))
            $errors[] = 'Username must be 3–30 characters (letters, numbers, underscore).';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';

        if (!$errors) {
            // Check username
            $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $chk->execute([$username]);
            if ($chk->fetch()) {
                $errors[] = 'Username is taken.';
            } else {
                $m = $pdo->prepare("SELECT * FROM members WHERE id = ? LIMIT 1");
                $m->execute([$memberId]);
                $member = $m->fetch();
                if (!$member) $errors[] = 'Member not found.';

                if (!$errors) {
                    $pdo->prepare("
                        INSERT INTO users
                        (username, email, password_hash, full_name, role, status, member_id, approved_by, approved_at)
                        VALUES (?, ?, ?, ?, 'member', 'approved', ?, ?, NOW())
                    ")->execute([
                        $username,
                        $member['email'] ?: null,
                        password_hash($password, PASSWORD_DEFAULT),
                        $member['first_name'] . ' ' . $member['last_name'],
                        $memberId,
                        $user['id'],
                    ]);
                    $newId = (int)$pdo->lastInsertId();

                    audit_log($pdo, (int)$user['id'], 'user.invite_member', 'user', $newId,
                        "Created portal account for member #$memberId ($username)");

                    flash('success',
                        "Portal account created. Username: {$username} — Password: {$password} (show once, save it.)");
                    redirect('/scms/admin/link-member.php');
                }
            }
        }
    }
}

// Data
$linkedUsers = $pdo->query("
    SELECT u.id, u.username, u.full_name, u.status, u.created_at,
           m.id AS member_id, m.member_no, m.first_name, m.last_name
    FROM users u
    JOIN members m ON m.id = u.member_id
    WHERE u.role = 'member'
    ORDER BY u.created_at DESC
")->fetchAll();

$unlinkedUsers = $pdo->query("
    SELECT id, username, full_name, role, status
    FROM users
    WHERE member_id IS NULL AND role <> 'member'
    ORDER BY full_name
")->fetchAll();

$unlinkedMembers = $pdo->query("
    SELECT m.id, m.member_no, m.first_name, m.last_name, m.status
    FROM members m
    WHERE m.id NOT IN (SELECT member_id FROM users WHERE member_id IS NOT NULL)
    ORDER BY m.first_name, m.last_name
")->fetchAll();

$pageTitle = 'Member Portal Access';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.dash-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); margin-bottom:14px; }
.field input, .field select {
    padding:11px 12px; border:1.5px solid var(--border); border-radius:8px;
    background:var(--surface); color:var(--text); font:inherit; font-size:.95rem;
}
.field input:focus, .field select:focus {
    outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,.12);
}
@media (max-width:900px) { .dash-grid { grid-template-columns:1fr; } }
</style>
<div class="page-head">
    <div>
        <h1>Member Portal Access</h1>
        <p>Grant members self-service access to their accounts.</p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <strong>Please fix:</strong>
        <ul style="margin-top:8px;">
            <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<div class="dash-grid">

    <!-- Invite new portal user -->
    <div class="card animate-fade-up">
        <h3 class="card-title" style="margin-bottom:12px;">Create portal account</h3>
        <p class="muted" style="font-size:.85rem;margin-bottom:14px;">
            Creates a new login and immediately links it to a member.
        </p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="invite">

            <label class="field"><span>Member *</span>
                <select name="member_id" required>
                    <option value="">— Select member —</option>
                    <?php foreach ($unlinkedMembers as $m): ?>
                        <option value="<?= (int)$m['id'] ?>">
                            <?= e($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['member_no'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <label class="field"><span>Username *</span>
                    <input type="text" name="username" required minlength="3">
                </label>
                <label class="field"><span>Temp password *</span>
                    <input type="text" name="password" required minlength="8"
                           value="<?= e('sac' . random_int(100000, 999999)) ?>">
                </label>
            </div>

            <button class="btn btn-primary btn-block" style="margin-top:12px;">
                Create &amp; link
            </button>
        </form>
    </div>

    <!-- Link existing user -->
    <div class="card animate-fade-up delay-1" style="align-self:flex-start;">
        <h3 class="card-title" style="margin-bottom:12px;">Link existing user</h3>
        <p class="muted" style="font-size:.85rem;margin-bottom:14px;">
            Use this if the user account already exists.
        </p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="link">

            <label class="field"><span>User account *</span>
                <select name="user_id" required>
                    <option value="">— Select user —</option>
                    <?php foreach ($unlinkedUsers as $u): ?>
                        <option value="<?= (int)$u['id'] ?>">
                            <?= e($u['full_name'] . ' (@' . $u['username'] . ') — ' . $u['role']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field"><span>Member *</span>
                <select name="member_id" required>
                    <option value="">— Select member —</option>
                    <?php foreach ($unlinkedMembers as $m): ?>
                        <option value="<?= (int)$m['id'] ?>">
                            <?= e($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['member_no'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <button class="btn btn-outline btn-block" style="margin-top:12px;">
                Link account
            </button>
        </form>
    </div>
</div>

<!-- Linked accounts -->
<?php if ($linkedUsers): ?>
    <div class="card animate-fade-up delay-2" style="margin-top:18px;">
        <h3 class="card-title" style="margin-bottom:14px;">Portal accounts</h3>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Username</th><th>Full name</th><th>Member</th><th>Status</th><th>Created</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($linkedUsers as $u): ?>
                    <tr>
                        <td><code><?= e($u['username']) ?></code></td>
                        <td><?= e($u['full_name']) ?></td>
                        <td>
                            <a href="/scms/members/view.php?id=<?= (int)$u['member_id'] ?>">
                                <?= e($u['first_name'] . ' ' . $u['last_name']) ?>
                            </a>
                            <div class="muted" style="font-size:.76rem;"><code><?= e($u['member_no']) ?></code></div>
                        </td>
                        <td><span class="badge badge-<?= e($u['status']) ?>"><?= e($u['status']) ?></span></td>
                        <td class="muted"><?= e(date('M j, Y', strtotime($u['created_at']))) ?></td>
                        <td style="text-align:right;">
                            <form method="post" style="display:inline;"
                                  onsubmit="return confirm('Unlink this portal account? The user will lose access.');">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="unlink">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-ghost btn-sm">Unlink</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>