<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_member();
$member = current_member();
require_once __DIR__ . '/../includes/requests.php';

$defaultRate   = (float)(setting('default_interest', '3'));
$defaultTerm   = (int)(setting('default_term', '6'));
$defaultMethod = setting('default_method', 'declining');

$errors = [];
$old = [
    'amount'          => '',
    'term_months'     => (string)$defaultTerm,
    'interest_rate'   => (string)$defaultRate,
    'interest_method' => $defaultMethod,
    'purpose'         => '',
    'notes'           => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $old['amount']          = trim((string)($_POST['amount'] ?? ''));
    $old['term_months']     = trim((string)($_POST['term_months'] ?? ''));
    $old['interest_rate']   = trim((string)($_POST['interest_rate'] ?? ''));
    $old['interest_method'] = $_POST['interest_method'] ?? 'declining';
    $old['purpose']         = trim((string)($_POST['purpose'] ?? ''));
    $old['notes']           = trim((string)($_POST['notes'] ?? ''));

    if (!is_numeric($old['amount']) || (float)$old['amount'] <= 0) $errors[] = 'Enter a valid amount.';
    if (!is_numeric($old['term_months']) || (int)$old['term_months'] < 1) $errors[] = 'Term must be at least 1 month.';
    if (!is_numeric($old['interest_rate']) || (float)$old['interest_rate'] < 0) $errors[] = 'Invalid interest rate.';
    if (!in_array($old['interest_method'], ['flat','declining'], true)) $old['interest_method'] = 'declining';

    if (!$errors) {
        try {
            $id = create_member_request($pdo, (int)$member['id'], 'loan', [
                'amount'          => (float)$old['amount'],
                'term_months'     => (int)$old['term_months'],
                'interest_rate'   => (float)$old['interest_rate'],
                'interest_method' => $old['interest_method'],
                'purpose'         => $old['purpose'] !== '' ? $old['purpose'] : null,
                'notes'           => $old['notes'] !== '' ? $old['notes'] : null,
            ]);
            audit_log($pdo, (int)$user['id'], 'portal.request_loan', 'member_request', $id,
                'Requested loan ' . money((float)$old['amount']));
            flash('success', 'Loan request submitted. Awaiting admin review.');
            redirect('/scms/portal/requests.php');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// Preview schedule if amount + term + rate are set
$preview = null;
if (is_numeric($old['amount']) && (float)$old['amount'] > 0 &&
    is_numeric($old['term_months']) && (int)$old['term_months'] > 0) {
    try {
        $preview = build_loan_schedule(
            (float)$old['amount'],
            (float)$old['interest_rate'],
            (int)$old['term_months'],
            add_months(date('Y-m-d'), 1),
            $old['interest_method']
        );
    } catch (Throwable $e) { /* ignore */ }
}

$pageTitle = 'Request Loan';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); margin-bottom:14px; }
.field input, .field select, .field textarea { padding:11px 12px; border:1.5px solid var(--border); border-radius:8px; background:var(--surface); color:var(--text); font:inherit; font-size:.95rem; }
.field input:focus, .field select:focus, .field textarea:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,.12); }
.mini-kpi { padding:12px; border:1px solid var(--border-2); border-radius:8px; background:var(--surface-2); }
@media (max-width:900px) { div[style*="grid-template-columns:1.2fr 1fr"] { grid-template-columns:1fr !important; } }
</style>
<div class="page-head">
    <div>
        <h1>Request a loan</h1>
        <p>Submit a loan application. Admin will review and, if approved, issue it from the office.</p>
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

<div style="display:grid;grid-template-columns:1.2fr 1fr;gap:18px;max-width:1100px;">

    <form method="post" class="card animate-fade-up">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label class="field"><span>Amount requested *</span>
            <input type="number" name="amount" step="0.01" min="0.01" value="<?= e($old['amount']) ?>" required>
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <label class="field"><span>Term (months) *</span>
                <input type="number" name="term_months" min="1" max="120" value="<?= e($old['term_months']) ?>" required>
            </label>
            <label class="field"><span>Interest rate (%/month)</span>
                <input type="number" name="interest_rate" step="0.001" min="0" value="<?= e($old['interest_rate']) ?>" required>
            </label>
        </div>

        <label class="field"><span>Interest method</span>
            <select name="interest_method">
                <option value="declining" <?= $old['interest_method'] === 'declining' ? 'selected' : '' ?>>Declining balance</option>
                <option value="flat"      <?= $old['interest_method'] === 'flat' ? 'selected' : '' ?>>Flat</option>
            </select>
        </label>

        <label class="field"><span>Purpose</span>
            <input type="text" name="purpose" value="<?= e($old['purpose']) ?>" placeholder="e.g. Business expansion">
        </label>

        <label class="field"><span>Additional notes</span>
            <textarea name="notes" rows="2"><?= e($old['notes']) ?></textarea>
        </label>

        <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:14px;">
            <a href="index.php" class="btn btn-ghost">Cancel</a>
            <button class="btn btn-primary">Submit request</button>
        </div>
    </form>

    <div class="card animate-fade-up delay-1" style="align-self:flex-start;">
        <h3 class="card-title" style="margin-bottom:14px;">Schedule preview</h3>
        <?php if ($preview): ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                <div class="mini-kpi"><div class="muted" style="font-size:.72rem;text-transform:uppercase;">Monthly</div>
                    <div style="font-weight:800;"><?= e(money((float)$preview['monthly_installment'])) ?></div></div>
                <div class="mini-kpi"><div class="muted" style="font-size:.72rem;text-transform:uppercase;">Total interest</div>
                    <div style="font-weight:800;"><?= e(money((float)$preview['total_interest'])) ?></div></div>
                <div class="mini-kpi"><div class="muted" style="font-size:.72rem;text-transform:uppercase;">Total payable</div>
                    <div style="font-weight:800;"><?= e(money((float)$preview['total_payable'])) ?></div></div>
                <div class="mini-kpi"><div class="muted" style="font-size:.72rem;text-transform:uppercase;">Installments</div>
                    <div style="font-weight:800;"><?= count($preview['schedule']) ?></div></div>
            </div>
            <div style="max-height:300px;overflow-y:auto;border:1px solid var(--border-2);border-radius:8px;">
                <table class="table" style="font-size:.8rem;">
                    <thead><tr><th>#</th><th>Due</th><th style="text-align:right;">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview['schedule'] as $s): ?>
                        <tr>
                            <td><?= (int)$s['installment_no'] ?></td>
                            <td><?= e(date('M j, Y', strtotime($s['due_date']))) ?></td>
                            <td style="text-align:right;"><?= e(money((float)$s['total_due'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="center muted" style="padding:32px;">
                <div style="font-size:2rem;margin-bottom:8px;">🧮</div>
                <div>Fill in the amount and term to see your schedule.</div>
            </div>
        <?php endif; ?>
    </div>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>