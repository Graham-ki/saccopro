<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/reversals.php';

$txnId = (int)($_GET['txn_id'] ?? $_POST['txn_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT st.*, sa.account_no, m.first_name, m.last_name, m.member_no, m.id AS member_id
    FROM savings_transactions st
    JOIN savings_accounts sa ON sa.id = st.account_id
    JOIN members m ON m.id = sa.member_id
    WHERE st.id = ? LIMIT 1
");
$stmt->execute([$txnId]);
$txn = $stmt->fetch();

if (!$txn) {
    flash('error', 'Transaction not found.');
    redirect('/scms/savings/history.php');
}

$errors = [];
$old = ['reason' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $old['reason'] = trim((string)($_POST['reason'] ?? ''));

    if ($old['reason'] === '') {
        $errors[] = 'A reason is required for the audit trail.';
    }

    if (!$errors) {
        try {
            $result = reverse_savings_transaction(
                $pdo,
                $txnId,
                (int)$user['id'],
                $old['reason']
            );

            audit_log($pdo, (int)$user['id'], 'savings.reverse', 'savings_transaction', $txnId,
                'Reversed ' . $txn['reference'] . ' — reason: ' . $old['reason']);

            flash('success',
                'Reversal posted (ref ' . $result['reference'] . '). New balance: ' .
                money($result['new_balance']));

            redirect('/scms/savings/statement.php?account_id=' . (int)$txn['account_id']);
        } catch (Throwable $ex) {
            error_log('[savings/reverse] ' . $ex->getMessage());
            $errors[] = $ex->getMessage();
        }
    }
}

$pageTitle = 'Reverse Transaction';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); }
.field textarea {
    padding:11px 12px; border:1.5px solid var(--border); border-radius:8px;
    background:var(--surface); color:var(--text); font:inherit; font-size:.95rem;
    resize:vertical;
}
.field textarea:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,.12); }
</style>
<div class="page-head">
    <div>
        <h1>Reverse transaction</h1>
        <p>Post a counter-entry to cancel this transaction. This cannot be undone.</p>
    </div>
    <div class="page-head-actions">
        <a href="/scms/savings/history.php" class="btn btn-ghost">← Back to history</a>
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

<div style="max-width:720px;">
    <div class="card animate-fade-up">
        <h3 class="card-title" style="margin-bottom:14px;">Original transaction</h3>
        <dl style="display:grid;grid-template-columns:1fr 1fr;gap:16px 24px;font-size:.9rem;">
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Reference</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><code><?= e($txn['reference']) ?></code></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Date</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= e(date('M j, Y', strtotime($txn['transaction_date']))) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Member</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= e($txn['first_name'] . ' ' . $txn['last_name']) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Account</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><code><?= e($txn['account_no']) ?></code></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Type</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= e(ucfirst($txn['type'])) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Amount</dt>
                <dd style="margin:4px 0 0;font-weight:800;color:<?= $txn['type'] === 'deposit' ? 'var(--accent)' : 'var(--danger)' ?>;">
                    <?= e(money((float)$txn['amount'])) ?>
                </dd>
            </div>
        </dl>
    </div>

    <div class="alert alert-warning" style="margin-top:18px;">
        <strong>⚠️ This cannot be undone.</strong>
        The system will post a
        <strong><?= $txn['type'] === 'deposit' ? 'withdrawal' : 'deposit' ?></strong>
        of <?= e(money((float)$txn['amount'])) ?> and mark the original as reversed.
    </div>

    <form method="post" class="card animate-fade-up delay-1" style="margin-top:18px;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="txn_id" value="<?= $txnId ?>">

        <label class="field">
            <span>Reason for reversal *</span>
            <textarea name="reason" rows="3" required
                      placeholder="e.g. Duplicate entry, wrong amount, member disputed"><?= e($old['reason']) ?></textarea>
        </label>

        <div style="display:flex;gap:12px;margin-top:20px;justify-content:flex-end;">
            <a href="/scms/savings/history.php" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-danger"
                    onclick="return confirm('Reverse this transaction? This action cannot be undone.');">
                Reverse transaction
            </button>
        </div>
    </form>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>