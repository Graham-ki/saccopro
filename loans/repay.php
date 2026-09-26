<?php
declare(strict_types=1);

// ---- Bootstrap (no output) ----
require_once __DIR__ . '/../includes/auth.php';
$user = require_login();

$loanId = (int)($_GET['loan_id'] ?? $_POST['loan_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT l.*, m.first_name, m.last_name, m.member_no
    FROM loans l JOIN members m ON m.id = l.member_id
    WHERE l.id = ? LIMIT 1
");
$stmt->execute([$loanId]);
$loan = $stmt->fetch();

if (!$loan) {
    flash('error', 'Loan not found.');
    redirect('/scms/loans/index.php');
}

$errors = [];
$old = [
    'amount'       => '',
    'payment_date' => date('Y-m-d'),
    'method'       => 'cash',
    'notes'        => '',
];

// ---- Handle POST before any HTML ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old['amount']       = trim((string)($_POST['amount'] ?? ''));
    $old['payment_date'] = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
    $old['method']       = $_POST['method'] ?? 'cash';
    $old['notes']        = trim((string)($_POST['notes'] ?? ''));

    if (!is_numeric($old['amount']) || (float)$old['amount'] <= 0) {
        $errors[] = 'Enter a valid amount.';
    }
    if ($old['payment_date'] === '') {
        $errors[] = 'Pick a payment date.';
    }
    if (!in_array($old['method'], ['cash','mobile_money','bank','other'], true)) {
        $old['method'] = 'cash';
    }

    if (!$errors && (float)$old['amount'] > (float)$loan['balance'] + 0.01) {
        $errors[] = 'Amount exceeds outstanding balance of ' . money($loan['balance']) . '.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $breakdown = allocate_repayment($pdo, $loanId, (float)$old['amount'], $old['payment_date']);

            $ref = next_repayment_reference($pdo);
            $pdo->prepare("
                INSERT INTO loan_repayments
                (loan_id, amount, principal_portion, interest_portion, payment_date, reference, method, notes, recorded_by)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                $loanId,
                (float)$old['amount'],
                $breakdown['principal_portion'],
                $breakdown['interest_portion'],
                $old['payment_date'],
                $ref,
                $old['method'],
                $old['notes'] !== '' ? $old['notes'] : null,
                $user['id'],
            ]);

            refresh_loan_totals($pdo, $loanId);

            $pdo->commit();
            audit_log($pdo, (int)$user['id'], 'loan.repay', 'loan', $loanId,
    'Repayment ' . money((float)$old['amount']) . " ref {$ref}");
            flash('success', 'Repayment of ' . money($old['amount']) . " recorded ({$ref}).");
            redirect('/scms/loans/view.php?id=' . $loanId);   // exits — clean

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[loans/repay] ' . $ex->getMessage());
            $errors[] = 'Could not record repayment: ' . $ex->getMessage();
        }
    }
}

// ---- Data for the page ----
$next = $pdo->prepare("
    SELECT * FROM loan_schedules
    WHERE loan_id = ? AND status IN ('pending','partial','overdue')
    ORDER BY installment_no ASC LIMIT 1
");
$next->execute([$loanId]);
$nextDue = $next->fetch();

// ---- Render ----
$pageTitle = 'Record Repayment';
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
    div[style*="grid-template-columns:1fr 1fr"] { grid-template-columns:1fr !important; }
}
</style>
<div class="page-head">
    <div>
        <h1>Record repayment</h1>
        <p>Loan <code><?= e($loan['loan_no']) ?></code> · <?= e($loan['first_name'] . ' ' . $loan['last_name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="view.php?id=<?= $loanId ?>" class="btn btn-ghost">← Loan</a>
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

<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;max-width:960px;">
    <form method="post" class="card animate-fade-up">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="loan_id" value="<?= $loanId ?>">

        <div style="padding:14px;border-radius:10px;background:var(--surface-2);border:1px solid var(--border-2);margin-bottom:18px;">
            <div class="muted" style="font-size:.75rem;text-transform:uppercase;">Outstanding balance</div>
            <div style="font-size:1.6rem;font-weight:800;"><?= e(money($loan['balance'])) ?></div>
            <?php if ($nextDue): ?>
                <div class="muted" style="font-size:.82rem;margin-top:6px;">
                    Next due: <strong><?= e(money($nextDue['total_due'])) ?></strong>
                    on <?= e(date('M j, Y', strtotime($nextDue['due_date']))) ?>
                </div>
            <?php endif; ?>
        </div>

        <label class="field"><span>Amount *</span>
            <input type="number" name="amount" step="0.01" min="0.01" value="<?= e($old['amount']) ?>" required autofocus>
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <label class="field"><span>Payment date *</span>
                <input type="date" name="payment_date" value="<?= e($old['payment_date']) ?>" required>
            </label>
            <label class="field"><span>Method</span>
                <select name="method">
                    <option value="cash"         <?= $old['method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="mobile_money" <?= $old['method'] === 'mobile_money' ? 'selected' : '' ?>>Mobile money</option>
                    <option value="bank"         <?= $old['method'] === 'bank' ? 'selected' : '' ?>>Bank</option>
                    <option value="other"        <?= $old['method'] === 'other' ? 'selected' : '' ?>>Other</option>
                </select>
            </label>
        </div>
        <label class="field"><span>Notes</span>
            <textarea name="notes" rows="2"><?= e($old['notes']) ?></textarea>
        </label>

        <div style="display:flex;gap:12px;margin-top:20px;justify-content:flex-end;">
            <a href="view.php?id=<?= $loanId ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Record repayment</button>
        </div>
    </form>

    <div class="card animate-fade-up delay-1" style="align-self:flex-start;">
        <h3 class="card-title" style="margin-bottom:14px;">Quick amounts</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <?php if ($nextDue): ?>
                <button type="button" class="btn btn-outline btn-sm q-amt" data-amt="<?= e((string)$nextDue['total_due']) ?>">
                    Next due (<?= e(money($nextDue['total_due'])) ?>)
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline btn-sm q-amt" data-amt="<?= e((string)$loan['balance']) ?>">
                Pay off (<?= e(money($loan['balance'])) ?>)
            </button>
            <button type="button" class="btn btn-outline btn-sm q-amt" data-amt="<?= e((string)$loan['monthly_installment']) ?>">
                Monthly (<?= e(money($loan['monthly_installment'])) ?>)
            </button>
        </div>
        <div class="muted" style="font-size:.8rem;margin-top:14px;line-height:1.5;">
            Repayments are allocated to the oldest unpaid installment first.
            Interest is cleared before principal within each installment.
        </div>
    </div>
</div>



<script>
document.querySelectorAll('.q-amt').forEach(b => {
    b.addEventListener('click', () => {
        document.querySelector('input[name="amount"]').value = b.dataset.amt;
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>