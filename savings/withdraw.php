<?php
declare(strict_types=1);

// ---- Bootstrap (no output) ----
require_once __DIR__ . '/../includes/auth.php';
$user = require_login();

// ---- Resolve account from query params ----
$accountId = (int)($_GET['account_id'] ?? 0);
$memberId  = (int)($_GET['member_id']  ?? 0);

if (!$accountId && $memberId) {
    $acc = account_for_member($pdo, $memberId);
    if ($acc) $accountId = (int)$acc['id'];
}

$errors = [];
$old = [
    'account_id' => $accountId,
    'amount'     => '',
    'txn_date'   => date('Y-m-d'),
    'notes'      => '',
];

// ---- Handle POST before any HTML ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old['account_id'] = (int)($_POST['account_id'] ?? 0);
    $old['amount']     = trim((string)($_POST['amount'] ?? ''));
    $old['txn_date']   = trim((string)($_POST['txn_date'] ?? date('Y-m-d')));
    $old['notes']      = trim((string)($_POST['notes'] ?? ''));

    if (!$old['account_id']) $errors[] = 'Choose a savings account.';
    if (!is_numeric($old['amount']) || (float)$old['amount'] <= 0) $errors[] = 'Enter a valid amount.';
    if ($old['txn_date'] === '') $errors[] = 'Pick a transaction date.';

    if (!$errors) {
        try {
            $result = record_savings_transaction(
                $pdo,
                $old['account_id'],
                'withdrawal',
                (float)$old['amount'],
                $old['txn_date'],
                $old['notes'] !== '' ? $old['notes'] : null,
                (int)$user['id']
            );
            audit_log($pdo, (int)$user['id'], 'savings.withdraw', 'savings_account', $old['account_id'],
    'Withdrawal ' . money((float)$old['amount']) . ' ref ' . $result['reference']);
            flash('success',
                'Withdrawal recorded. Ref ' . $result['reference'] .
                ' · New balance ' . money($result['balance_after']));

            redirect('/scms/savings/statement.php?account_id=' . $old['account_id']);   // exits — clean
        } catch (Throwable $ex) {
            error_log('[savings/withdraw] ' . $ex->getMessage());
            $errors[] = $ex->getMessage();
        }
    }
}

// ---- Data for the form (after POST so re-render shows correct account) ----
$selectedAccount = null;
if ($old['account_id']) {
    $stmt = $pdo->prepare("
        SELECT sa.*, m.first_name, m.last_name, m.member_no
        FROM savings_accounts sa JOIN members m ON m.id = sa.member_id
        WHERE sa.id = ? LIMIT 1
    ");
    $stmt->execute([$old['account_id']]);
    $selectedAccount = $stmt->fetch() ?: null;
}

$accountList = $pdo->query("
    SELECT sa.id, sa.account_no, sa.balance, m.first_name, m.last_name, m.member_no
    FROM savings_accounts sa JOIN members m ON m.id = sa.member_id
    WHERE m.status = 'active'
    ORDER BY m.first_name, m.last_name
")->fetchAll();

// ---- Render ----
$pageTitle = 'Record Withdrawal';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.field {
    display:flex; flex-direction:column; gap:6px;
    font-size:.85rem; font-weight:600; color:var(--text-2);
    margin-bottom:16px;
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
@media (max-width:900px) {
    div[style*="grid-template-columns:2fr 1fr"] { grid-template-columns:1fr !important; }
    div[style*="grid-template-columns:1fr 1fr"] { grid-template-columns:1fr !important; }
}
</style>
<div class="page-head">
    <div>
        <h1>Record withdrawal</h1>
        <p>Withdraw funds from a member's savings account.</p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Savings</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <strong>Please fix the following:</strong>
        <ul style="margin-top:8px;">
            <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:18px;max-width:1000px;">
    <form method="post" class="card animate-fade-up">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label class="field">
            <span>Savings account *</span>
            <select name="account_id" id="accountSelect" required>
                <option value="">— Select account —</option>
                <?php foreach ($accountList as $a): ?>
                    <?php
                        $label = $a['account_no'] . ' · ' . $a['first_name'] . ' ' . $a['last_name'] .
                                 ' (' . $a['member_no'] . ') — ' . money($a['balance']);
                        $sel = ((int)$old['account_id'] === (int)$a['id']) ? 'selected' : '';
                    ?>
                    <option value="<?= (int)$a['id'] ?>" data-balance="<?= e((string)$a['balance']) ?>" <?= $sel ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <label class="field">
                <span>Amount *</span>
                <input type="number" name="amount" step="0.01" min="0.01"
                       value="<?= e($old['amount']) ?>" placeholder="0.00" required>
            </label>
            <label class="field">
                <span>Date *</span>
                <input type="date" name="txn_date" value="<?= e($old['txn_date']) ?>" required>
            </label>
        </div>

        <label class="field">
            <span>Reason / notes (optional)</span>
            <textarea name="notes" rows="3" placeholder="e.g. Emergency withdrawal"><?= e($old['notes']) ?></textarea>
        </label>

        <div style="display:flex;gap:12px;margin-top:24px;justify-content:flex-end;">
            <a href="index.php" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-warning">Record withdrawal</button>
        </div>
    </form>

    <div class="card animate-fade-up delay-1" style="align-self:flex-start;">
        <h3 class="card-title" style="margin-bottom:14px;">Available balance</h3>
        <div id="availableBalance" style="font-size:1.8rem;font-weight:800;">
            <?= $selectedAccount ? e(money($selectedAccount['balance'])) : '—' ?>
        </div>
        <div class="muted" style="font-size:.82rem;margin-top:4px;">
            <?= $selectedAccount
                ? e($selectedAccount['first_name'] . ' ' . $selectedAccount['last_name'])
                : 'Pick an account to see balance' ?>
        </div>
    </div>
</div>



<script>
const sel = document.getElementById('accountSelect');
const bal = document.getElementById('availableBalance');
sel?.addEventListener('change', () => {
    const opt = sel.options[sel.selectedIndex];
    const v = opt?.dataset?.balance;
    bal.textContent = v ? new Intl.NumberFormat().format(+v) : '—';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>