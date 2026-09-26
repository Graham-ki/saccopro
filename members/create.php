<?php
declare(strict_types=1);

// ---- Bootstrap (no output) ----
require_once __DIR__ . '/../includes/auth.php';
$user = require_login();

$errors = [];
$old = [
    'first_name' => '', 'last_name' => '', 'phone' => '', 'email' => '',
    'gender' => '', 'dob' => '', 'address' => '', 'id_number' => '',
    'join_date' => date('Y-m-d'), 'status' => 'active', 'notes' => '',
];

// ---- Handle POST (must exit before any HTML) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    foreach ($old as $k => $_) {
        $old[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if ($old['first_name'] === '') $errors[] = 'First name is required.';
    if ($old['last_name'] === '')  $errors[] = 'Last name is required.';
    if ($old['phone'] === '')      $errors[] = 'Phone number is required.';

    if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is invalid.';
    }
    if ($old['join_date'] === '') {
        $errors[] = 'Join date is required.';
    }
    if (!in_array($old['status'], ['active', 'inactive'], true)) {
        $old['status'] = 'active';
    }
    if ($old['gender'] !== '' && !in_array($old['gender'], ['male', 'female', 'other'], true)) {
        $old['gender'] = '';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $memberNo  = next_member_no($pdo);
            $accountNo = next_account_no($pdo);

            $stmt = $pdo->prepare(
                "INSERT INTO members
                 (member_no, first_name, last_name, phone, email, gender, dob, address, id_number, join_date, status, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $memberNo,
                $old['first_name'],
                $old['last_name'],
                $old['phone'],
                $old['email']     !== '' ? $old['email']     : null,
                $old['gender']    !== '' ? $old['gender']    : null,
                $old['dob']       !== '' ? $old['dob']       : null,
                $old['address']   !== '' ? $old['address']   : null,
                $old['id_number'] !== '' ? $old['id_number'] : null,
                $old['join_date'],
                $old['status'],
                $old['notes']     !== '' ? $old['notes']     : null,
                $user['id'],
            ]);
            $memberId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO savings_accounts (member_id, account_no) VALUES (?, ?)")
                ->execute([$memberId, $accountNo]);

            $pdo->commit();
            audit_log($pdo, (int)$user['id'], 'member.create', 'member', $memberId, "Created {$old['first_name']} {$old['last_name']} ({$memberNo})");

            flash('success', "Member {$old['first_name']} {$old['last_name']} added (member no. {$memberNo}, savings account {$accountNo}).");
            redirect('/scms/members/view.php?id=' . $memberId);   // exits here — clean

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[members/create] ' . $ex->getMessage());
            $errors[] = 'Could not save member: ' . $ex->getMessage();
        }
    }
}

// ---- Render (HTML now safe to emit) ----
$pageTitle = 'Add Member';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.field {
    display:flex; flex-direction:column; gap:6px;
    font-size:.85rem; font-weight:600; color:var(--text-2);
}
.field input, .field select, .field textarea {
    padding:11px 12px; border:1.5px solid var(--border); border-radius:8px;
    background:var(--surface); color:var(--text); font:inherit; font-size:.95rem;
    transition:border-color .2s, box-shadow .2s;
    width:100%;
}
.field input:focus, .field select:focus, .field textarea:focus {
    outline:none; border-color:var(--primary);
    box-shadow:0 0 0 4px rgba(79,70,229,.12);
}
@media (max-width:640px) {
    form.card > div[style*="grid-template-columns"] { grid-template-columns:1fr !important; }
}
</style>
<div class="page-head">
    <div>
        <h1>Add member</h1>
        <p>Register a new member. A savings account is created automatically.</p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Back to list</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <strong>Please fix the following:</strong>
        <ul style="margin-top:8px;">
            <?php foreach ($errors as $er): ?>
                <li><?= e($er) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="card" style="max-width:900px;">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <h3 class="card-title" style="margin-bottom:16px;">Personal details</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <label class="field">
            <span>First name *</span>
            <input type="text" name="first_name" value="<?= e($old['first_name']) ?>" required>
        </label>
        <label class="field">
            <span>Last name *</span>
            <input type="text" name="last_name" value="<?= e($old['last_name']) ?>" required>
        </label>
        <label class="field">
            <span>Gender</span>
            <select name="gender">
                <option value="">—</option>
                <option value="male"   <?= $old['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                <option value="female" <?= $old['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                <option value="other"  <?= $old['gender'] === 'other' ? 'selected' : '' ?>>Other</option>
            </select>
        </label>
        <label class="field">
            <span>Date of birth</span>
            <input type="date" name="dob" value="<?= e($old['dob']) ?>">
        </label>
    </div>

    <h3 class="card-title" style="margin:24px 0 16px;">Contact</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <label class="field">
            <span>Phone *</span>
            <input type="text" name="phone" value="<?= e($old['phone']) ?>" required>
        </label>
        <label class="field">
            <span>Email</span>
            <input type="email" name="email" value="<?= e($old['email']) ?>">
        </label>
        <label class="field" style="grid-column:1 / -1;">
            <span>Address</span>
            <input type="text" name="address" value="<?= e($old['address']) ?>">
        </label>
        <label class="field">
            <span>ID number</span>
            <input type="text" name="id_number" value="<?= e($old['id_number']) ?>">
        </label>
    </div>

    <h3 class="card-title" style="margin:24px 0 16px;">Membership</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <label class="field">
            <span>Join date *</span>
            <input type="date" name="join_date" value="<?= e($old['join_date']) ?>" required>
        </label>
        <label class="field">
            <span>Status</span>
            <select name="status">
                <option value="active"   <?= $old['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $old['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </label>
        <label class="field" style="grid-column:1 / -1;">
            <span>Notes</span>
            <textarea name="notes" rows="3"><?= e($old['notes']) ?></textarea>
        </label>
    </div>

    <div style="display:flex;gap:12px;margin-top:24px;justify-content:flex-end;">
        <a href="index.php" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">Save member</button>
    </div>
</form>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>