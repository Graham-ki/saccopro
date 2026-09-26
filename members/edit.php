<?php
declare(strict_types=1);

// ---- Bootstrap (no output) ----
require_once __DIR__ . '/../includes/auth.php';
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM members WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$member = $stmt->fetch();

if (!$member) {
    flash('error', 'Member not found.');
    redirect('/scms/members/index.php');   // exits — no output yet, safe
}

$errors = [];
$old = $member;

// ---- Handle POST (must exit before any HTML) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $fields = ['first_name', 'last_name', 'phone', 'email', 'gender', 'dob',
               'address', 'id_number', 'join_date', 'status', 'notes'];
    foreach ($fields as $f) {
        $old[$f] = trim((string)($_POST[$f] ?? ''));
    }

    if ($old['first_name'] === '') $errors[] = 'First name is required.';
    if ($old['last_name'] === '')  $errors[] = 'Last name is required.';
    if ($old['phone'] === '')      $errors[] = 'Phone number is required.';
    if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is invalid.';
    }
    if (!in_array($old['status'], ['active', 'inactive'], true)) {
        $old['status'] = 'active';
    }
    if (!in_array($old['gender'], ['male', 'female', 'other', ''], true)) {
        $old['gender'] = '';
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                "UPDATE members SET
                    first_name = ?, last_name = ?, phone = ?, email = ?, gender = ?,
                    dob = ?, address = ?, id_number = ?, join_date = ?, status = ?, notes = ?
                 WHERE id = ?"
            );
            $stmt->execute([
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
                $id,
            ]);
            audit_log($pdo, (int)$user['id'], 'member.update', 'member', $id, 'Updated member details');
            flash('success', 'Member updated successfully.');
            redirect('/scms/members/view.php?id=' . $id);   // exits here — clean

        } catch (Throwable $ex) {
            error_log('[members/edit] ' . $ex->getMessage());
            $errors[] = 'Could not update member: ' . $ex->getMessage();
        }
    }
}

// ---- Render (HTML now safe to emit) ----
$pageTitle = 'Edit Member';
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
        <h1>Edit member</h1>
        <p>Update details for <strong><?= e($member['first_name'] . ' ' . $member['last_name']) ?></strong> (<?= e($member['member_no']) ?>).</p>
    </div>
    <div class="page-head-actions">
        <a href="view.php?id=<?= $id ?>" class="btn btn-ghost">← Cancel</a>
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
        <label class="field"><span>First name *</span>
            <input type="text" name="first_name" value="<?= e($old['first_name']) ?>" required></label>
        <label class="field"><span>Last name *</span>
            <input type="text" name="last_name" value="<?= e($old['last_name']) ?>" required></label>
        <label class="field"><span>Gender</span>
            <select name="gender">
                <option value="">—</option>
                <option value="male"   <?= $old['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                <option value="female" <?= $old['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                <option value="other"  <?= $old['gender'] === 'other' ? 'selected' : '' ?>>Other</option>
            </select></label>
        <label class="field"><span>Date of birth</span>
            <input type="date" name="dob" value="<?= e($old['dob']) ?>"></label>
    </div>

    <h3 class="card-title" style="margin:24px 0 16px;">Contact</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <label class="field"><span>Phone *</span>
            <input type="text" name="phone" value="<?= e($old['phone']) ?>" required></label>
        <label class="field"><span>Email</span>
            <input type="email" name="email" value="<?= e($old['email']) ?>"></label>
        <label class="field" style="grid-column:1 / -1;"><span>Address</span>
            <input type="text" name="address" value="<?= e($old['address']) ?>"></label>
        <label class="field"><span>ID number</span>
            <input type="text" name="id_number" value="<?= e($old['id_number']) ?>"></label>
    </div>

    <h3 class="card-title" style="margin:24px 0 16px;">Membership</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <label class="field"><span>Join date *</span>
            <input type="date" name="join_date" value="<?= e($old['join_date']) ?>" required></label>
        <label class="field"><span>Status</span>
            <select name="status">
                <option value="active"   <?= $old['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $old['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select></label>
        <label class="field" style="grid-column:1 / -1;"><span>Notes</span>
            <textarea name="notes" rows="3"><?= e($old['notes']) ?></textarea></label>
    </div>

    <div style="display:flex;gap:12px;margin-top:24px;justify-content:flex-end;">
        <a href="view.php?id=<?= $id ?>" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">Save changes</button>
    </div>
</form>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>