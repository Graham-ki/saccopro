<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/shares.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT sp.*, m.first_name, m.last_name, m.member_no
    FROM share_purchases sp
    JOIN members m ON m.id = sp.member_id
    WHERE sp.id = ? LIMIT 1
");
$stmt->execute([$id]);
$p = $stmt->fetch();

if (!$p) {
    flash('error', 'Purchase not found.');
    redirect('/scms/shares/index.php');
}

$errors = [];
$old = ['reason' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $old['reason'] = trim((string)($_POST['reason'] ?? ''));

    if ($old['reason'] === '') $errors[] = 'A reason is required.';

    if (!$errors) {
        try {
            $result = reverse_share_purchase($pdo, $id, (int)$user['id'], $old['reason']);

            audit_log($pdo, (int)$user['id'], 'share.reverse', 'share_purchase', $id,
                'Reversed ' . $p['receipt_no'] . ' — ' . $old['reason']);

            flash('success', 'Purchase reversed.');
            redirect('/scms/shares/member.php?id=' . (int)$p['member_id']);
        } catch (Throwable $ex) {
            error_log('[shares/reverse] ' . $ex->getMessage());
            $errors[] = $ex->getMessage();
        }
    }
}

$pageTitle = 'Reverse Share Purchase';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); }
.field textarea {
    padding:11px 12px; border:1.5px solid var(--border); border-radius:8px;
    background:var(--surface); color:var(--text); font:inherit; font-size:.95rem;
}
.field textarea:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,.12); }
</style>
<div class="page-head">
    <div>
        <h1>Reverse share purchase</h1>
        <p><?= e($p['receipt_no']) ?> · <?= e($p['first_name'] . ' ' . $p['last_name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="member.php?id=<?= (int)$p['member_id'] ?>" class="btn btn-ghost">← Statement</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <strong>Cannot reverse:</strong>
        <ul style="margin-top:8px;">
            <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div style="max-width:640px;">
    <div class="card animate-fade-up">
        <dl style="display:grid;grid-template-columns:1fr 1fr;gap:16px 24px;font-size:.9rem;">
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Receipt</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><code><?= e($p['receipt_no']) ?></code></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Date</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= e(date('M j, Y', strtotime($p['purchase_date']))) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Quantity</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= number_format((int)$p['qty']) ?> shares</dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Total</dt>
                <dd style="margin:4px 0 0;font-weight:800;"><?= e(money((float)$p['total_amount'])) ?></dd>
            </div>
        </dl>
    </div>

    <div class="alert alert-warning" style="margin-top:16px;">
        <strong>⚠️ This cannot be undone.</strong>
        The shares will be cancelled
        <?php if ((int)$p['savings_txn_id'] > 0): ?>
            and the linked savings withdrawal (transaction #<?= (int)$p['savings_txn_id'] ?>) will be reversed.
        <?php endif; ?>
    </div>

    <form method="post" class="card animate-fade-up delay-1" style="margin-top:16px;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">

        <label class="field">
            <span>Reason for reversal *</span>
            <textarea name="reason" rows="3" required
                      placeholder="e.g. Duplicate entry, member disputed, wrong amount"><?= e($old['reason']) ?></textarea>
        </label>

        <div style="display:flex;gap:12px;margin-top:16px;justify-content:flex-end;">
            <a href="member.php?id=<?= (int)$p['member_id'] ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-danger"
                    onclick="return confirm('Reverse this share purchase? This action cannot be undone.');">
                Reverse purchase
            </button>
        </div>
    </form>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>