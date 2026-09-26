<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_admin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    // ---------- Create a brand-new portal account for a member ----------
    if ($action === 'invite') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = trim((string)($_POST['password'] ?? ''));

        if (!$memberId) $errors[] = 'Choose a member.';
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            $errors[] = 'Username must be 3–30 characters (letters, numbers, underscore).';
        }
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';

        if (!$errors) {
            $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $chk->execute([$username]);
            if ($chk->fetch()) {
                $errors[] = 'Username is already taken.';
            }
        }

        if (!$errors) {
            $m = $pdo->prepare("SELECT * FROM members WHERE id = ? LIMIT 1");
            $m->execute([$memberId]);
            $member = $m->fetch();
            if (!$member) $errors[] = 'Member not found.';

            // Refuse if the member already has a portal account
            if (!$errors) {
                $exists = $pdo->prepare("SELECT id FROM users WHERE member_id = ? LIMIT 1");
                $exists->execute([$memberId]);
                if ($exists->fetch()) {
                    $errors[] = 'This member already has a portal account.';
                }
            }
        }

        if (!$errors) {
            try {
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
                    (int)$user['id'],
                ]);
                $newId = (int)$pdo->lastInsertId();

                audit_log($pdo, (int)$user['id'], 'portal.create_account', 'user', $newId,
                    "Created portal account for member #{$memberId} ({$username})");

                flash('success',
                    "Portal account created. Username: {$username} — Temporary password: {$password} " .
                    "(share it with the member; they should change it after first login).");
                redirect('/scms/admin/link-member.php');
            } catch (Throwable $ex) {
                error_log('[link-member] ' . $ex->getMessage());
                $errors[] = 'Could not create account: ' . $ex->getMessage();
            }
        }
    }

    // ---------- Link an existing user account to a member ----------
    if ($action === 'link') {
        $userId   = (int)($_POST['user_id'] ?? 0);
        $memberId = (int)($_POST['member_id'] ?? 0);

        if (!$userId) $errors[] = 'Choose a user.';
        if (!$memberId) $errors[] = 'Choose a member.';

        if (!$errors) {
            // Ensure the member isn't already linked
            $chk = $pdo->prepare("SELECT id FROM users WHERE member_id = ? AND id <> ? LIMIT 1");
            $chk->execute([$memberId, $userId]);
            if ($chk->fetch()) $errors[] = 'That member is already linked to another user.';

            // Ensure the user isn't already linked
            $chk2 = $pdo->prepare("SELECT member_id FROM users WHERE id = ? LIMIT 1");
            $chk2->execute([$userId]);
            $row = $chk2->fetch();
            if ($row && $row['member_id']) {
                $errors[] = 'That user is already linked to a member. Unlink first.';
            }
        }

        if (!$errors) {
            $pdo->prepare("UPDATE users SET member_id = ?, role = 'member', status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?")
                ->execute([$memberId, (int)$user['id'], $userId]);

            audit_log($pdo, (int)$user['id'], 'portal.link_user', 'user', $userId,
                "Linked user #{$userId} to member #{$memberId}");

            flash('success', 'User linked to member. They can now access the member portal.');
            redirect('/scms/admin/link-member.php');
        }
    }

    // ---------- Unlink ----------
    if ($action === 'unlink') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId) {
            $pdo->prepare("UPDATE users SET member_id = NULL WHERE id = ?")->execute([$userId]);
            audit_log($pdo, (int)$user['id'], 'portal.unlink_user', 'user', $userId,
                "Unlinked member from user #{$userId}");
            flash('success', 'Portal account unlinked. The user can no longer access the portal.');
        }
        redirect('/scms/admin/link-member.php');
    }

    // ---------- Reset password ----------
    if ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPass = trim((string)($_POST['new_password'] ?? ''));

        if (!$userId) $errors[] = 'Missing user.';
        if (strlen($newPass) < 8) $errors[] = 'Password must be at least 8 characters.';

        if (!$errors) {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($newPass, PASSWORD_DEFAULT), $userId]);
            audit_log($pdo, (int)$user['id'], 'portal.reset_password', 'user', $userId,
                'Reset portal password');
            flash('success', "Password reset. New password: {$newPass} (share it with the member).");
            redirect('/scms/admin/link-member.php');
        }
    }
}

// ---------- Data for the page ----------
$linkedUsers = $pdo->query("
    SELECT u.id, u.username, u.email, u.full_name, u.status, u.created_at, u.last_login,
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
    width:100%;
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
    <div class="page-head-actions">
        <a href="/scms/admin/requests.php" class="btn btn-ghost">Member Requests →</a>
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
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><?= e($msg) ?></div>
<?php endif; ?>

<div class="dash-grid">

    <!-- Create new portal account -->
    <div class="card animate-fade-up">
        <h3 class="card-title" style="margin-bottom:12px;">Create portal account</h3>
        <p class="muted" style="font-size:.85rem;margin-bottom:14px;">
            Creates a new login and immediately links it to a member.
            Share the username and password with the member; they can change it after first login.
        </p>

        <?php if (!$unlinkedMembers): ?>
            <div class="alert alert-info" style="margin:0;">
                Every active member already has a portal account.
            </div>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="invite">

                <label class="field"><span>Member *</span>
                    <select name="member_id" required>
                        <option value="">— Select member —</option>
                        <?php foreach ($unlinkedMembers as $m): ?>
                            <option value="<?= (int)$m['id'] ?>">
                                <?= e($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['member_no'] . ')') ?>
                                <?= $m['status'] !== 'active' ? ' — inactive' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <label class="field"><span>Username *</span>
                        <input type="text" name="username" required minlength="3"
                               pattern="[a-zA-Z0-9_]{3,30}"
                               placeholder="e.g. jmukasa">
                    </label>
                    <label class="field"><span>Temporary password *</span>
                        <input type="text" name="password" required minlength="8"
                               value="<?= e('SAC' . random_int(100000, 999999)) ?>">
                    </label>
                </div>

                <button class="btn btn-primary btn-block" style="margin-top:12px;">
                    Create &amp; link
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Link existing user -->
    <div class="card animate-fade-up delay-1" style="align-self:flex-start;">
        <h3 class="card-title" style="margin-bottom:12px;">Link existing user</h3>
        <p class="muted" style="font-size:.85rem;margin-bottom:14px;">
            Use this if the user account already exists in the system.
            The user's role will be changed to <code>member</code>.
        </p>

        <?php if (!$unlinkedUsers || !$unlinkedMembers): ?>
            <div class="alert alert-info" style="margin:0;">
                No users or members available to link.
            </div>
        <?php else: ?>
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
        <?php endif; ?>
    </div>
</div>

<!-- Linked accounts -->
<?php if ($linkedUsers): ?>
    <div class="card animate-fade-up delay-2" style="margin-top:18px;">
        <h3 class="card-title" style="margin-bottom:14px;">Portal accounts</h3>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full name</th>
                        <th>Member</th>
                        <th>Status</th>
                        <th>Last login</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
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
                        <td>
                            <span class="badge badge-<?= e($u['status']) ?>"><?= e($u['status']) ?></span>
                        </td>
                        <td class="muted" style="font-size:.82rem;">
                            <?= $u['last_login'] ? e(date('M j, Y g:ia', strtotime($u['last_login']))) : 'Never' ?>
                        </td>
                        <td style="text-align:right;">
                            <button type="button" class="btn btn-ghost btn-sm"
                                    onclick="openReset(<?= (int)$u['id'] ?>, '<?= e($u['username']) ?>')">
                                Reset password
                            </button>
                            <form method="post" style="display:inline;"
                                  onsubmit="return confirm('Unlink this portal account? The user will lose portal access.');">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="unlink">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-ghost btn-sm" style="color:var(--danger);">Unlink</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Reset password modal -->
<div id="resetModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.55);align-items:center;justify-content:center;padding:20px;">
    <form method="post" class="card" style="max-width:440px;width:100%;margin:0;"
          onsubmit="return confirm('Reset this password?');">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" id="resetUserId">

        <h3 class="card-title" style="margin-bottom:8px;">Reset password</h3>
        <p class="muted" id="resetWho" style="font-size:.9rem;margin-bottom:14px;"></p>

        <label class="field"><span>New password *</span>
            <input type="text" name="new_password" id="resetPassword" required minlength="8"
                   value="<?= e('SAC' . random_int(100000, 999999)) ?>">
        </label>

        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px;">
            <button type="button" class="btn btn-ghost" onclick="closeReset()">Cancel</button>
            <button type="submit" class="btn btn-primary">Reset &amp; show password</button>
        </div>
    </form>
</div>



<script>
function openReset(id, username) {
    document.getElementById('resetUserId').value = id;
    document.getElementById('resetWho').textContent = 'Resetting password for @' + username;
    document.getElementById('resetPassword').value = 'SAC' + Math.floor(100000 + Math.random() * 900000);
    document.getElementById('resetModal').style.display = 'flex';
}
function closeReset() {
    document.getElementById('resetModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>