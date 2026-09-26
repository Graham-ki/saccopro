<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_member();
$member = current_member();
require_once __DIR__ . '/../includes/requests.php';

$errors = [];
$old = ['amount' => '', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $old['amount'] = trim((string)($_POST['amount'] ?? ''));
    $old['notes']  = trim((string)($_POST['notes'] ?? ''));

    if (!is_numeric($old['amount']) || (float)$old['amount'] <= 0) $errors[] = 'Enter a valid amount.';

    if (!$errors) {
        try {
            $id = create_member_request($pdo, (int)$member['id'], 'deposit', [
                'amount' => (float)$old['amount'],
                'notes'  => $old['notes'] !== '' ? $old['notes'] : null,
            ]);
            audit_log($pdo, (int)$user['id'], 'portal.request_deposit', 'member_request', $id,
                'Requested deposit ' . money((float)$old['amount']));
            flash('success', 'Deposit request submitted. An admin will review it shortly.');
            redirect('/scms/portal/requests.php');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'Request Deposit';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); margin-bottom:14px; }
.field input, .field textarea { padding:11px 12px; border:1.5px solid var(--border); border-radius:8px; background:var(--surface); color:var(--text); font:inherit; font-size:.95rem; }
.field input:focus, .field textarea:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,.12); }
</style>
<div class="page-head">
    <div>
        <h1>Request a deposit</h1>
        <p>Submit a deposit slip for approval. Bring the cash to the office or transfer directly.</p>
    </div>
    <div class="page-head-actions">
        <a href="requests.php" class="btn btn-ghost">← My Requests</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <ul style="margin:0;padding-left:18px;">
            <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div style="max-width:600px;">
    <form method="post" class="card animate-fade-up">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label class="field"><span>Amount *</span>
            <input type="number" name="amount" step="0.01" min="0.01"
                   value="<?= e($old['amount']) ?>" required autofocus>
        </label>

        <label class="field"><span>Notes (optional)</span>
            <textarea name="notes" rows="3" placeholder="e.g. Monthly contribution for March"><?= e($old['notes']) ?></textarea>
        </label>

        <div class="alert alert-info" style="font-size:.85rem;">
            Once approved, the deposit will be posted to your savings account
            (<code><?= e($member['account_no'] ?: '—') ?></code>) by an admin.
        </div>

        <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:14px;">
            <a href="index.php" class="btn btn-ghost">Cancel</a>
            <button class="btn btn-primary">Submit request</button>
        </div>
    </form>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>