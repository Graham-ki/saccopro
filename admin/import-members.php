<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_admin();

$step    = $_POST['step'] ?? $_GET['step'] ?? 'upload';
$preview = [];
$errors  = [];
$success = null;
$tmpPath = $_SESSION['import_members_tmp'] ?? null;

// ---- STEP 1: Upload & parse ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'upload') {
    csrf_check($_POST['csrf'] ?? null);

    if (empty($_FILES['csvfile']) || $_FILES['csvfile']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'No CSV file uploaded.';
    } else {
        $tmp = $_FILES['csvfile']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['csvfile']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            $errors[] = 'File must be .csv or .txt';
        } elseif (!is_uploaded_file($tmp)) {
            $errors[] = 'Upload security check failed.';
        } else {
            // Save to a session-scoped temp file
            $dest = sys_get_temp_dir() . '/scms-import-' . session_id() . '-' . time() . '.csv';
            if (move_uploaded_file($tmp, $dest)) {
                $_SESSION['import_members_tmp'] = $dest;
                $tmpPath = $dest;
                $step = 'preview';
            } else {
                $errors[] = 'Could not move uploaded file.';
            }
        }
    }
}

// ---- Parse the CSV for preview ----
if ($step === 'preview' && $tmpPath && file_exists($tmpPath)) {
    $fh = fopen($tmpPath, 'r');
    if (!$fh) {
        $errors[] = 'Could not read uploaded file.';
    } else {
        // Skip BOM
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($fh);

        $header = fgetcsv($fh);
        if (!$header) {
            $errors[] = 'CSV appears to be empty.';
        } else {
            // Normalize headers
            $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);
            $row = 1;
            while (($data = fgetcsv($fh)) !== false) {
                $row++;
                if (count(array_filter($data, fn($v) => trim((string)$v) !== '')) === 0) continue; // skip empty

                $rec = array_combine($header, array_pad($data, count($header), '')) ?: [];
                $rec['_row'] = $row;

                // Validate
                $recErrs = [];
                if (trim((string)($rec['first_name'] ?? '')) === '') $recErrs[] = 'first_name missing';
                if (trim((string)($rec['last_name'] ?? ''))  === '') $recErrs[] = 'last_name missing';
                if (trim((string)($rec['phone'] ?? ''))      === '') $recErrs[] = 'phone missing';
                if (!empty($rec['email']) && !filter_var($rec['email'], FILTER_VALIDATE_EMAIL))
                    $recErrs[] = 'invalid email';

                $rec['_errors'] = $recErrs;
                $preview[] = $rec;
            }
        }
        fclose($fh);
    }
}

// ---- STEP 2: Confirm & insert ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'commit') {
    csrf_check($_POST['csrf'] ?? null);

    $tmp = $_SESSION['import_members_tmp'] ?? null;
    if (!$tmp || !file_exists($tmp)) {
        flash('error', 'Upload expired. Please start again.');
        redirect('/scms/admin/import-members.php');
    }

    $fh = fopen($tmp, 'r');
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $header = fgetcsv($fh);
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);

    $inserted = 0;
    $skipped  = 0;
    $rowErrors = [];

    try {
        $pdo->beginTransaction();

        while (($data = fgetcsv($fh)) !== false) {
            $rec = array_combine($header, array_pad($data, count($header), '')) ?: [];

            $first = trim((string)($rec['first_name'] ?? ''));
            $last  = trim((string)($rec['last_name'] ?? ''));
            $phone = trim((string)($rec['phone'] ?? ''));

            if ($first === '' || $last === '' || $phone === '') {
                $skipped++;
                continue;
            }

            $email     = trim((string)($rec['email'] ?? '')) ?: null;
            $gender    = trim((string)($rec['gender'] ?? '')) ?: null;
            $dob       = trim((string)($rec['dob'] ?? '')) ?: null;
            $address   = trim((string)($rec['address'] ?? '')) ?: null;
            $idNumber  = trim((string)($rec['id_number'] ?? '')) ?: null;
            $joinDate  = trim((string)($rec['join_date'] ?? '')) ?: date('Y-m-d');
            $notes     = trim((string)($rec['notes'] ?? '')) ?: null;

            // Validate gender
            if ($gender && !in_array($gender, ['male','female','other'], true)) $gender = null;

            // Validate join_date
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $joinDate)) $joinDate = date('Y-m-d');

            $memberNo  = next_member_no($pdo);
            $accountNo = next_account_no($pdo);

            $pdo->prepare("
                INSERT INTO members
                (member_no, first_name, last_name, phone, email, gender, dob, address, id_number, join_date, status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
            ")->execute([
                $memberNo, $first, $last, $phone, $email, $gender, $dob,
                $address, $idNumber, $joinDate, $notes, $user['id'],
            ]);
            $memberId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO savings_accounts (member_id, account_no) VALUES (?, ?)")
                ->execute([$memberId, $accountNo]);

            $inserted++;
        }

        $pdo->commit();
        fclose($fh);

        audit_log($pdo, (int)$user['id'], 'members.bulk_import', null, null,
            "Imported {$inserted}, skipped {$skipped}");

        @unlink($tmp);
        unset($_SESSION['import_members_tmp']);

        flash('success', "Imported {$inserted} member(s). Skipped {$skipped} row(s) with missing data.");
        redirect('/scms/members/index.php');

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fclose($fh);
        error_log('[import-members] ' . $e->getMessage());
        $errors[] = 'Import failed: ' . $e->getMessage();
    }
}

$pageTitle = 'Import Members';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.dash-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); margin-bottom:14px; }
.field input[type="file"] {
    padding:11px 12px; border:1.5px solid var(--border); border-radius:8px;
    background:var(--surface); color:var(--text); font:inherit; font-size:.9rem;
}
@media (max-width:900px) { .dash-grid { grid-template-columns:1fr; } }
</style>
<div class="page-head">
    <div>
        <h1>Import members from CSV</h1>
        <p>Bulk-add members. Each gets a member number and savings account automatically.</p>
    </div>
    <div class="page-head-actions">
        <a href="/scms/admin/backup.php" class="btn btn-ghost">← Backup</a>
        <a href="/scms/members/index.php" class="btn btn-ghost">Members</a>
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

<?php /* ---------------- STEP 1: UPLOAD ---------------- */ ?>
<?php if ($step === 'upload'): ?>

    <div class="dash-grid">
        <div class="card animate-fade-up">
            <h3 class="card-title" style="margin-bottom:14px;">Upload CSV</h3>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="step" value="upload">

                <label class="field">
                    <span>CSV file *</span>
                    <input type="file" name="csvfile" accept=".csv,.txt" required>
                    <small class="muted" style="font-size:.75rem;">First row must be headers (see template on the right).</small>
                </label>

                <button class="btn btn-primary btn-block" style="margin-top:14px;">Upload &amp; preview</button>
            </form>
        </div>

        <div class="card animate-fade-up delay-1">
            <h3 class="card-title" style="margin-bottom:14px;">CSV format</h3>
            <p class="muted" style="font-size:.88rem;margin-bottom:12px;">Required columns: <strong>first_name</strong>, <strong>last_name</strong>, <strong>phone</strong>. Everything else is optional.</p>

            <div class="table-wrap" style="margin-bottom:14px;">
                <table class="table" style="font-size:.8rem;">
                    <thead><tr><th>Column</th><th>Notes</th></tr></thead>
                    <tbody>
                        <tr><td><code>first_name</code></td><td>Required</td></tr>
                        <tr><td><code>last_name</code></td><td>Required</td></tr>
                        <tr><td><code>phone</code></td><td>Required</td></tr>
                        <tr><td><code>email</code></td><td>Optional, must be valid</td></tr>
                        <tr><td><code>gender</code></td><td>male / female / other</td></tr>
                        <tr><td><code>dob</code></td><td>YYYY-MM-DD</td></tr>
                        <tr><td><code>address</code></td><td>Free text</td></tr>
                        <tr><td><code>id_number</code></td><td>Free text</td></tr>
                        <tr><td><code>join_date</code></td><td>YYYY-MM-DD (defaults to today)</td></tr>
                        <tr><td><code>notes</code></td><td>Free text</td></tr>
                    </tbody>
                </table>
            </div>

            <a href="/scms/admin/import-members-template.php" class="btn btn-outline btn-block">⬇ Download sample template</a>
        </div>
    </div>

<?php /* ---------------- STEP 2: PREVIEW ---------------- */ ?>
<?php elseif ($step === 'preview'): ?>

    <?php
        $validRows = array_filter($preview, fn($r) => empty($r['_errors']));
        $invalidRows = array_filter($preview, fn($r) => !empty($r['_errors']));
    ?>

    <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:18px;">
        <div class="stat animate-fade-up">
            <div class="stat-label">Total rows parsed</div>
            <div class="stat-value"><?= count($preview) ?></div>
        </div>
        <div class="stat animate-fade-up delay-1">
            <div class="stat-label">Ready to import</div>
            <div class="stat-value" style="color:var(--accent);"><?= count($validRows) ?></div>
        </div>
        <div class="stat animate-fade-up delay-2">
            <div class="stat-label">With errors (will skip)</div>
            <div class="stat-value" style="color:var(--danger);"><?= count($invalidRows) ?></div>
        </div>
    </div>

    <div class="card animate-fade-up">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
            <div>
                <div class="card-title">Preview</div>
                <div class="card-sub">Review the first 50 rows before importing.</div>
            </div>
            <form method="post" onsubmit="return confirm('Import <?= count($validRows) ?> members now?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="step" value="commit">
                <button class="btn btn-primary" <?= count($validRows) === 0 ? 'disabled' : '' ?>>
                    ✓ Import <?= count($validRows) ?> member<?= count($validRows) === 1 ? '' : 's' ?>
                </button>
            </form>
        </div>

        <div class="table-wrap" style="max-height:520px;overflow-y:auto;">
            <table class="table" style="font-size:.82rem;">
                <thead>
                    <tr>
                        <th>Row</th>
                        <th>First name</th>
                        <th>Last name</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Join date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($preview, 0, 50) as $r): ?>
                    <tr style="<?= !empty($r['_errors']) ? 'background:rgba(239,68,68,.06);' : '' ?>">
                        <td><?= (int)$r['_row'] ?></td>
                        <td><?= e($r['first_name'] ?? '') ?></td>
                        <td><?= e($r['last_name'] ?? '') ?></td>
                        <td><?= e($r['phone'] ?? '') ?></td>
                        <td><?= e($r['email'] ?? '') ?></td>
                        <td><?= e($r['join_date'] ?? '') ?></td>
                        <td>
                            <?php if (!empty($r['_errors'])): ?>
                                <span class="badge badge-rejected" title="<?= e(implode(', ', $r['_errors'])) ?>">
                                    <?= e(implode(', ', $r['_errors'])) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-approved">OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:14px;display:flex;gap:10px;justify-content:space-between;flex-wrap:wrap;">
            <a href="import-members.php" class="btn btn-ghost">← Upload a different file</a>
            <span class="muted" style="font-size:.82rem;align-self:center;">
                Rows with errors will be skipped. Nothing is imported until you click Import.
            </span>
        </div>
    </div>

<?php endif; ?>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>