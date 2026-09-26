<?php
declare(strict_types=1);

// ---- Bootstrap (no output) ----
require_once __DIR__ . '/../includes/auth.php';
$user = require_login();

$errors = [];
$old = [
    'member_id'        => (int)($_GET['member_id'] ?? 0),
    'principal'        => '',
    'interest_rate'    => '3',
    'interest_method'  => 'declining',
    'term_months'      => '6',
    'issue_date'       => date('Y-m-d'),
    'first_due_date'   => add_months(date('Y-m-d'), 1),
    'purpose'          => '',
    'notes'            => '',
];

// ---- Handle POST before any HTML ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old['member_id']       = (int)($_POST['member_id'] ?? 0);
    $old['principal']       = trim((string)($_POST['principal'] ?? ''));
    $old['interest_rate']   = trim((string)($_POST['interest_rate'] ?? ''));
    $old['interest_method'] = $_POST['interest_method'] ?? 'declining';
    $old['term_months']     = trim((string)($_POST['term_months'] ?? ''));
    $old['issue_date']      = trim((string)($_POST['issue_date'] ?? date('Y-m-d')));
    $old['first_due_date']  = trim((string)($_POST['first_due_date'] ?? add_months(date('Y-m-d'), 1)));
    $old['purpose']         = trim((string)($_POST['purpose'] ?? ''));
    $old['notes']           = trim((string)($_POST['notes'] ?? ''));

    if (!$old['member_id']) $errors[] = 'Select a member.';
    if (!is_numeric($old['principal']) || (float)$old['principal'] <= 0)
        $errors[] = 'Principal must be a positive number.';
    if (!is_numeric($old['interest_rate']) || (float)$old['interest_rate'] < 0)
        $errors[] = 'Interest rate must be 0 or greater.';
    if (!is_numeric($old['term_months']) || (int)$old['term_months'] < 1)
        $errors[] = 'Term must be at least 1 month.';
    if (!in_array($old['interest_method'], ['flat','declining'], true))
        $old['interest_method'] = 'declining';

    if (!$errors) {
        $chk = $pdo->prepare("SELECT id FROM members WHERE id = ? AND status='active' LIMIT 1");
        $chk->execute([$old['member_id']]);
        if (!$chk->fetch()) $errors[] = 'Selected member does not exist or is inactive.';
    }

    if (!$errors) {
        try {
            $sched = build_loan_schedule(
                (float)$old['principal'],
                (float)$old['interest_rate'],
                (int)$old['term_months'],
                $old['first_due_date'],
                $old['interest_method']
            );
        } catch (Throwable $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $loanNo   = next_loan_no($pdo);
            $maturity = add_months($old['first_due_date'], (int)$old['term_months'] - 1);

            $stmt = $pdo->prepare("
                INSERT INTO loans
                (loan_no, member_id, principal, interest_rate, interest_method, term_months,
                 total_interest, total_payable, amount_paid, balance, monthly_installment,
                 issue_date, first_due_date, maturity_date, purpose, notes, status,
                 approved_by, created_by)
                VALUES (?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,'active',?,?)
            ");
            $stmt->execute([
                $loanNo,
                $old['member_id'],
                (float)$old['principal'],
                (float)$old['interest_rate'],
                $old['interest_method'],
                (int)$old['term_months'],
                $sched['total_interest'],
                $sched['total_payable'],
                $sched['total_payable'],
                $sched['monthly_installment'],
                $old['issue_date'],
                $old['first_due_date'],
                $maturity,
                $old['purpose'] !== '' ? $old['purpose'] : null,
                $old['notes']   !== '' ? $old['notes']   : null,
                $user['id'],
                $user['id'],
            ]);
            $loanId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare("
                INSERT INTO loan_schedules
                (loan_id, installment_no, due_date, principal_due, interest_due, total_due)
                VALUES (?,?,?,?,?,?)
            ");
            foreach ($sched['schedule'] as $s) {
                $ins->execute([
                    $loanId, $s['installment_no'], $s['due_date'],
                    $s['principal_due'], $s['interest_due'], $s['total_due'],
                ]);
            }

            $pdo->commit();
            audit_log($pdo, (int)$user['id'], 'loan.create', 'loan', $loanId,
    "Issued {$loanNo} principal " . money((float)$old['principal']));
            flash('success',
                "Loan {$loanNo} issued · " . money($old['principal']) .
                " · Monthly " . money($sched['monthly_installment']) .
                " · Total payable " . money($sched['total_payable'])
            );

            redirect('/scms/loans/view.php?id=' . $loanId);   // exits — clean

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[loans/create] ' . $ex->getMessage());
            $errors[] = 'Could not save loan: ' . $ex->getMessage();
        }
    }
}

// ---- Data for the form ----
$members = $pdo->query("
    SELECT id, member_no, first_name, last_name
    FROM members WHERE status = 'active'
    ORDER BY first_name, last_name
")->fetchAll();

// ---- Render ----
$pageTitle = 'Issue Loan';
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
.mini-kpi { padding:12px; border:1px solid var(--border-2); border-radius:8px; background:var(--surface-2); }
@media (max-width:900px) {
    div[style*="grid-template-columns:1fr 1fr"]:not(.field) { grid-template-columns:1fr !important; }
}
</style>
<div class="page-head">
    <div>
        <h1>Issue loan</h1>
        <p>Configure the loan terms — the repayment schedule is generated automatically.</p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Loans</a>
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

<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;max-width:1100px;">
    <form method="post" class="card animate-fade-up" id="loanForm">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label class="field">
            <span>Member *</span>
            <select name="member_id" required>
                <option value="">— Select member —</option>
                <?php foreach ($members as $mem): ?>
                    <option value="<?= (int)$mem['id'] ?>" <?= (int)$old['member_id'] === (int)$mem['id'] ? 'selected' : '' ?>>
                        <?= e($mem['first_name'] . ' ' . $mem['last_name'] . ' (' . $mem['member_no'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <label class="field"><span>Principal *</span>
                <input type="number" name="principal" id="f_principal" step="0.01" min="0.01" value="<?= e($old['principal']) ?>" required>
            </label>
            <label class="field"><span>Term (months) *</span>
                <input type="number" name="term_months" id="f_term" min="1" max="120" value="<?= e($old['term_months']) ?>" required>
            </label>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <label class="field"><span>Interest rate (%/month) *</span>
                <input type="number" name="interest_rate" id="f_rate" step="0.001" min="0" value="<?= e($old['interest_rate']) ?>" required>
            </label>
            <label class="field"><span>Interest method</span>
                <select name="interest_method" id="f_method">
                    <option value="declining" <?= $old['interest_method'] === 'declining' ? 'selected' : '' ?>>Declining balance</option>
                    <option value="flat"      <?= $old['interest_method'] === 'flat' ? 'selected' : '' ?>>Flat (on principal)</option>
                </select>
            </label>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <label class="field"><span>Issue date *</span>
                <input type="date" name="issue_date" value="<?= e($old['issue_date']) ?>" required>
            </label>
            <label class="field"><span>First due date *</span>
                <input type="date" name="first_due_date" id="f_first_due" value="<?= e($old['first_due_date']) ?>" required>
            </label>
        </div>

        <label class="field"><span>Purpose</span>
            <input type="text" name="purpose" value="<?= e($old['purpose']) ?>" placeholder="e.g. Business expansion">
        </label>

        <label class="field"><span>Notes</span>
            <textarea name="notes" rows="2"><?= e($old['notes']) ?></textarea>
        </label>

        <div style="display:flex;gap:12px;margin-top:20px;justify-content:flex-end;">
            <a href="index.php" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Issue loan</button>
        </div>
    </form>

    <!-- Live preview panel -->
    <div class="card animate-fade-up delay-1" style="align-self:flex-start;">
        <h3 class="card-title" style="margin-bottom:14px;">Live schedule preview</h3>
        <div id="previewWrap">
            <div style="text-align:center;padding:40px 20px;">
                <div style="font-size:2rem;margin-bottom:8px;">🧮</div>
                <div style="font-weight:600;margin-bottom:4px;">Fill in the details</div>
                <div class="muted" style="font-size:.88rem;">The schedule updates as you type.</div>
            </div>
        </div>
    </div>
</div>



<script>
(function () {
    const form = document.getElementById('loanForm');
    const wrap = document.getElementById('previewWrap');
    const csrf = form.querySelector('input[name="csrf"]').value;

    function fmt(n) { return new Intl.NumberFormat().format(Math.round(n)); }

    function render(data) {
        if (!data || !data.schedule) {
            wrap.innerHTML = `<div class="muted" style="padding:20px;text-align:center;">Enter valid values to see the schedule.</div>`;
            return;
        }
        const rows = data.schedule.map(s => `
            <tr>
                <td>${s.installment_no}</td>
                <td>${s.due_date}</td>
                <td style="text-align:right;">${fmt(s.principal_due)}</td>
                <td style="text-align:right;">${fmt(s.interest_due)}</td>
                <td style="text-align:right;font-weight:600;">${fmt(s.total_due)}</td>
            </tr>
        `).join('');

        wrap.innerHTML = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                <div class="mini-kpi"><div class="muted" style="font-size:.72rem;text-transform:uppercase;">Monthly</div>
                    <div style="font-weight:800;font-size:1.1rem;">${fmt(data.monthly_installment)}</div></div>
                <div class="mini-kpi"><div class="muted" style="font-size:.72rem;text-transform:uppercase;">Total interest</div>
                    <div style="font-weight:800;font-size:1.1rem;">${fmt(data.total_interest)}</div></div>
                <div class="mini-kpi"><div class="muted" style="font-size:.72rem;text-transform:uppercase;">Total payable</div>
                    <div style="font-weight:800;font-size:1.1rem;">${fmt(data.total_payable)}</div></div>
                <div class="mini-kpi"><div class="muted" style="font-size:.72rem;text-transform:uppercase;">Installments</div>
                    <div style="font-weight:800;font-size:1.1rem;">${data.schedule.length}</div></div>
            </div>
            <div style="max-height:340px;overflow-y:auto;border:1px solid var(--border-2);border-radius:8px;">
                <table class="table" style="font-size:.82rem;">
                    <thead><tr><th>#</th><th>Due</th><th style="text-align:right;">Principal</th><th style="text-align:right;">Interest</th><th style="text-align:right;">Total</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;
    }

    let timer = null;
    function fetchPreview() {
        const fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('principal',       document.getElementById('f_principal').value);
        fd.append('interest_rate',   document.getElementById('f_rate').value);
        fd.append('term_months',     document.getElementById('f_term').value);
        fd.append('interest_method', document.getElementById('f_method').value);
        fd.append('first_due_date',  document.getElementById('f_first_due').value);

        if (!fd.get('principal') || !fd.get('term_months') || !fd.get('first_due_date')) return;

        fetch('preview.php', { method: 'POST', body: fd })
            .then(r => r.ok ? r.json() : r.json().then(e => Promise.reject(e)))
            .then(render)
            .catch(err => {
                wrap.innerHTML = `<div class="alert alert-error" style="margin:0;">${err.error || 'Could not preview.'}</div>`;
            });
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(fetchPreview, 350);
    }

    ['f_principal','f_rate','f_term','f_method','f_first_due'].forEach(id => {
        const el = document.getElementById(id);
        el?.addEventListener('input', schedule);
        el?.addEventListener('change', schedule);
    });

    fetchPreview();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>