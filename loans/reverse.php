<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/reversals.php';

$repaymentId = (int)($_GET['repayment_id'] ?? $_POST['repayment_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT r.*, l.loan_no, l.id AS loan_id,
           m.first_name, m.last_name, m.member_no
    FROM loan_repayments r
    JOIN loans l ON l.id = r.loan_id
    JOIN members m ON m.id = l.member_id
    WHERE r.id = ? LIMIT 1
");
$stmt->execute([$repaymentId]);
$rep = $stmt->fetch();

if (!$rep) {
    flash('error', 'Repayment not found.');
    redirect('/scms/loans/repayments.php');
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
            $result = reverse_loan_repayment($pdo, $repaymentId, (int)$user['id'], $old['reason']);

            audit_log($pdo, (int)$user['id'], 'loan.reverse_repayment', 'loan_repayment', $repaymentId,
                'Reversed ' . $rep['reference'] . ' — reason: ' . $old['reason']);

            flash('success', 'Repayment reversed. Loan balance updated.');
            redirect('/scms/loans/view.php?id=' . (int)$rep['loan_id']);
        } catch (Throwable $ex) {
            error_log('[loans/reverse] ' . $ex->getMessage());
            $errors[] = $ex->getMessage();
        }
    }
}

$pageTitle = 'Reverse Repayment';
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
        <h1>Reverse repayment</h1>
        <p>Posts a counter-entry and rebuilds the loan schedule.</p>
    </div>
    <div class="page-head-actions">
        <a href="/scms/loans/repayments.php" class="btn btn-ghost">← Back</a>
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
        <h3 class="card-title" style="margin-bottom:14px;">Original repayment</h3>
        <dl style="display:grid;grid-template-columns:1fr 1fr;gap:16px 24px;font-size:.9rem;">
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Reference</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><code><?= e($rep['reference']) ?></code></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Date</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= e(date('M j, Y', strtotime($rep['payment_date']))) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Loan</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><code><?= e($rep['loan_no']) ?></code></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Member</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= e($rep['first_name'] . ' ' . $rep['last_name']) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Amount</dt>
                <dd style="margin:4px 0 0;font-weight:800;"><?= e(money((float)$rep['amount'])) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Method</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= e(ucfirst(str_replace('_',' ', $rep['method']))) ?></dd>
            </div>
        </dl>
    </div>

    <div class="alert alert-warning" style="margin-top:18px;">
        <strong>⚠️ This cannot be undone.</strong>
        The loan schedule will be recalculated. Any follow-up repayments will remain in place.
    </div>

    <form method="post" class="card animate-fade-up delay-1" style="margin-top:18px;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="repayment_id" value="<?= $repaymentId ?>">

        <label class="field">
            <span>Reason for reversal *</span>
            <textarea name="reason" rows="3" required
                      placeholder="e.g. Wrong amount entered, duplicate payment, member disputed"><?= e($old['reason']) ?></textarea>
        </label>

        <div style="display:flex;gap:12px;margin-top:20px;justify-content:flex-end;">
            <a href="/scms/loans/repayments.php" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-danger"
                    onclick="return confirm('Reverse this repayment? This action cannot be undone.');">
                Reverse repayment
            </button>
        </div>
    </form>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>