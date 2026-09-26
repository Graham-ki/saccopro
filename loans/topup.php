<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/loans-topup.php';

$loanId = (int)($_GET['loan_id'] ?? $_POST['loan_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT l.*, m.first_name, m.last_name, m.member_no, m.id AS member_id
    FROM loans l JOIN members m ON m.id = l.member_id
    WHERE l.id = ? LIMIT 1
");
$stmt->execute([$loanId]);
$loan = $stmt->fetch();

if (!$loan) {
    flash('error', 'Loan not found.');
    redirect('/scms/loans/index.php');
}

// Fetch any previous top-ups
$topups = $pdo->prepare("
    SELECT t.*, u.full_name AS recorded_by_name
    FROM loan_topups t
    LEFT JOIN users u ON u.id = t.recorded_by
    WHERE t.loan_id = ?
    ORDER BY t.topup_date DESC, t.id DESC
");
$topups->execute([$loanId]);
$topups = $topups->fetchAll();

$errors = [];
$old = [
    'amount'   => '',
    'topup_date' => date('Y-m-d'),
    'method'   => 'cash',
    'notes'    => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old['amount']     = trim((string)($_POST['amount'] ?? ''));
    $old['topup_date'] = trim((string)($_POST['topup_date'] ?? date('Y-m-d')));
    $old['method']     = $_POST['method'] ?? 'cash';
    $old['notes']      = trim((string)($_POST['notes'] ?? ''));

    if (!is_numeric($old['amount']) || (float)$old['amount'] <= 0) {
        $errors[] = 'Enter a valid top-up amount.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['topup_date'])) {
        $errors[] = 'Pick a valid date.';
    }
    if (!in_array($old['method'], ['cash','mobile_money','bank','other'], true)) {
        $old['method'] = 'cash';
    }
    if ($loan['status'] !== 'active') {
        $errors[] = 'Only active loans can be topped up.';
    }

    if (!$errors) {
        try {
            $result = topup_loan(
                $pdo,
                $loanId,
                (float)$old['amount'],
                $old['topup_date'],
                $old['method'],
                $old['notes'] !== '' ? $old['notes'] : null,
                (int)$user['id']
            );

            audit_log($pdo, (int)$user['id'], 'loan.topup', 'loan', $loanId,
                'Topped up ' . money((float)$old['amount']) . ' — new balance ' . money($result['new_balance']));

            flash('success',
                'Top-up of ' . money((float)$old['amount']) . ' added. ' .
                'New balance: ' . money($result['new_balance']) .
                ' · New monthly: ' . money($result['monthly']));
            redirect('/scms/loans/view.php?id=' . $loanId);

        } catch (Throwable $ex) {
            error_log('[loans/topup] ' . $ex->getMessage());
            $errors[] = $ex->getMessage();
        }
    }
}

$pageTitle = 'Top Up Loan';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); margin-bottom:16px; }
.field input, .field select, .field textarea {
    padding:11px 12px; border:1.5px solid var(--border); border-radius:8px;
    background:var(--surface); color:var(--text); font:inherit; font-size:.95rem;
}
.field input:focus, .field select:focus, .field textarea:focus {
    outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,.12);
}
@media (max-width:900px) {
    div[style*="grid-template-columns:2fr 1fr"] { grid-template-columns:1fr !important; }
    div[style*="grid-template-columns:1fr 1fr"] { grid-template-columns:1fr !important; }
    div[style*="grid-template-columns:repeat(3,1fr)"] { grid-template-columns:1fr !important; }
}
</style>
<div class="page-head">
    <div>
        <h1>Top up loan</h1>
        <p>Loan <code><?= e($loan['loan_no']) ?></code> · <?= e($loan['first_name'] . ' ' . $loan['last_name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="view.php?id=<?= $loanId ?>" class="btn btn-ghost">← Loan</a>
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

<div style="display:grid;grid-template-columns:2fr 1fr;gap:18px;max-width:1000px;">

    <form method="post" class="card animate-fade-up">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="loan_id" value="<?= $loanId ?>">

        <div style="padding:14px;border-radius:10px;background:var(--surface-2);border:1px solid var(--border-2);margin-bottom:18px;">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;font-size:.85rem;">
                <div>
                    <div class="muted" style="font-size:.72rem;text-transform:uppercase;">Current principal</div>
                    <div style="font-weight:700;"><?= e(money((float)$loan['principal'])) ?></div>
                </div>
                <div>
                    <div class="muted" style="font-size:.72rem;text-transform:uppercase;">Balance</div>
                    <div style="font-weight:700;"><?= e(money((float)$loan['balance'])) ?></div>
                </div>
                <div>
                    <div class="muted" style="font-size:.72rem;text-transform:uppercase;">Monthly</div>
                    <div style="font-weight:700;"><?= e(money((float)$loan['monthly_installment'])) ?></div>
                </div>
            </div>
        </div>

        <label class="field"><span>Top-up amount *</span>
            <input type="number" name="amount" step="0.01" min="0.01" value="<?= e($old['amount']) ?>" required>
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <label class="field"><span>Date *</span>
                <input type="date" name="topup_date" value="<?= e($old['topup_date']) ?>" required>
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
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Top up this loan? The repayment schedule will be recalculated.');">
                Add top-up
            </button>
        </div>
    </form>

    <div class="card animate-fade-up delay-1" style="align-self:flex-start;">
        <h3 class="card-title" style="margin-bottom:12px;">How top-ups work</h3>
        <ul style="font-size:.85rem;color:var(--text-2);padding-left:18px;line-height:1.7;">
            <li>Paid installments stay in the ledger unchanged.</li>
            <li>Unpaid installments are recalculated on the new principal.</li>
            <li>Interest is recomputed on the outstanding balance only.</li>
            <li>The loan's total payable increases accordingly.</li>
        </ul>
    </div>
</div>

<?php if ($topups): ?>
    <div class="card animate-fade-up delay-2" style="margin-top:18px;">
        <h3 class="card-title" style="margin-bottom:12px;">Top-up history</h3>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th style="text-align:right;">Amount</th>
                        <th style="text-align:right;">New principal</th>
                        <th style="text-align:right;">New balance</th>
                        <th>Method</th>
                        <th>Notes</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($topups as $t): ?>
                    <tr>
                        <td><?= e(date('M j, Y', strtotime($t['topup_date']))) ?></td>
                        <td style="text-align:right;font-weight:700;color:var(--accent);">+<?= e(money((float)$t['amount'])) ?></td>
                        <td style="text-align:right;"><?= e(money((float)$t['new_principal'])) ?></td>
                        <td style="text-align:right;"><?= e(money((float)$t['new_balance'])) ?></td>
                        <td><span class="badge badge-info"><?= e(ucfirst(str_replace('_',' ', $t['method']))) ?></span></td>
                        <td class="muted" style="font-size:.82rem;"><?= e($t['notes'] ?: '—') ?></td>
                        <td class="muted" style="font-size:.82rem;"><?= e($t['recorded_by_name'] ?: 'System') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>