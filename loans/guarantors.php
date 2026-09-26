<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/guarantors.php';

$loanId = (int)($_GET['loan_id'] ?? $_POST['loan_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT l.*, m.id AS borrower_id, m.first_name, m.last_name, m.member_no
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
$old = ['member_id' => '', 'amount_guaranteed' => '', 'notes' => ''];

// ---- Handle POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $old['member_id']         = (int)($_POST['member_id'] ?? 0);
        $old['amount_guaranteed'] = trim((string)($_POST['amount_guaranteed'] ?? ''));
        $old['notes']             = trim((string)($_POST['notes'] ?? ''));

        if (!$old['member_id']) $errors[] = 'Select a member.';
        if (!is_numeric($old['amount_guaranteed']) || (float)$old['amount_guaranteed'] <= 0) {
            $errors[] = 'Enter a valid guarantee amount.';
        }

        if (!$errors) {
            try {
                $gid = add_guarantor(
                    $pdo,
                    $loanId,
                    (int)$old['member_id'],
                    (float)$old['amount_guaranteed'],
                    $old['notes'] !== '' ? $old['notes'] : null,
                    (int)$user['id']
                );

                audit_log($pdo, (int)$user['id'], 'loan.guarantor_add', 'loan', $loanId,
                    "Added guarantor member #{$old['member_id']} for " . money((float)$old['amount_guaranteed']));

                flash('success', 'Guarantor added.');
                redirect('/scms/loans/guarantors.php?loan_id=' . $loanId);
            } catch (Throwable $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }

    if ($action === 'release') {
        $gid    = (int)($_POST['guarantor_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));

        if (!$gid) $errors[] = 'Invalid guarantor.';
        if ($reason === '') $errors[] = 'A release reason is required.';

        if (!$errors) {
            try {
                release_guarantor($pdo, $gid, (int)$user['id'], $reason);
                audit_log($pdo, (int)$user['id'], 'loan.guarantor_release', 'loan', $loanId,
                    "Released guarantor #{$gid}: {$reason}");
                flash('success', 'Guarantor released.');
                redirect('/scms/loans/guarantors.php?loan_id=' . $loanId);
            } catch (Throwable $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }
}

// ---- Data for the page ----
$guarantors = $pdo->prepare("
    SELECT g.*, m.first_name, m.last_name, m.member_no,
           u1.full_name AS added_by_name,
           u2.full_name AS released_by_name
    FROM loan_guarantors g
    JOIN members m ON m.id = g.member_id
    LEFT JOIN users u1 ON u1.id = g.added_by
    LEFT JOIN users u2 ON u2.id = g.released_by
    WHERE g.loan_id = ?
    ORDER BY g.status ASC, g.created_at DESC
");
$guarantors->execute([$loanId]);
$guarantors = $guarantors->fetchAll();

$totalActiveGuaranteed = 0.0;
foreach ($guarantors as $g) {
    if ($g['status'] === 'active') $totalActiveGuaranteed += (float)$g['amount_guaranteed'];
}

// Members available as guarantors (active, not the borrower)
$candidates = $pdo->prepare("
    SELECT m.id, m.member_no, m.first_name, m.last_name,
           COALESCE(sa.balance, 0) AS savings_balance,
           COALESCE((
               SELECT SUM(g.amount_guaranteed)
               FROM loan_guarantors g
               WHERE g.member_id = m.id AND g.status = 'active'
           ), 0) AS already_guaranteed
    FROM members m
    LEFT JOIN savings_accounts sa ON sa.member_id = m.id
    WHERE m.status = 'active'
      AND m.id <> ?
      AND m.id NOT IN (
          SELECT member_id FROM loan_guarantors
          WHERE loan_id = ? AND status = 'active'
      )
    ORDER BY m.first_name, m.last_name
");
$candidates->execute([(int)$loan['borrower_id'], $loanId]);
$candidates = $candidates->fetchAll();

$pageTitle = 'Loan Guarantors';
require_once __DIR__ . '/../includes/header.php';
?>
</script>

<style>
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); margin-bottom:14px; }
.field input, .field select, .field textarea {
    padding:11px 12px; border:1.5px solid var(--border); border-radius:8px;
    background:var(--surface); color:var(--text); font:inherit; font-size:.95rem;
    width:100%;
}
.field input:focus, .field select:focus, .field textarea:focus {
    outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,.12);
}
@media (max-width:900px) {
    div[style*="grid-template-columns:1.4fr 1fr"] { grid-template-columns:1fr !important; }
}
</style>
<div class="page-head">
    <div>
        <h1>Guarantors</h1>
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
<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<!-- Summary strip -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:18px;">
    <div class="stat animate-fade-up">
        <div class="stat-label">Loan principal</div>
        <div class="stat-value"><?= e(money((float)$loan['principal'])) ?></div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-label">Guaranteed (active)</div>
        <div class="stat-value" style="color:var(--accent);"><?= e(money($totalActiveGuaranteed)) ?></div>
        <div class="stat-trend muted">
            <?= count(array_filter($guarantors, fn($g) => $g['status'] === 'active')) ?> guarantor<?= count(array_filter($guarantors, fn($g) => $g['status'] === 'active')) === 1 ? '' : 's' ?>
        </div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-label">Coverage</div>
        <div class="stat-value" style="font-size:1.3rem;">
            <?= (float)$loan['principal'] > 0
                ? number_format(min(100, $totalActiveGuaranteed / (float)$loan['principal'] * 100), 0) . '%'
                : '—' ?>
        </div>
        <div class="stat-trend muted">of principal guaranteed</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:18px;">

    <!-- Existing guarantors -->
    <div class="card animate-fade-up">
        <h3 class="card-title" style="margin-bottom:14px;">Current guarantors</h3>
        <?php if ($guarantors): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th style="text-align:right;">Guaranteed</th>
                            <th>Status</th>
                            <th>Added by</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($guarantors as $g): ?>
                        <tr style="<?= $g['status'] !== 'active' ? 'opacity:.6;' : '' ?>">
                            <td>
                                <a href="/scms/members/view.php?id=<?= (int)$g['member_id'] ?>" style="font-weight:600;">
                                    <?= e($g['first_name'] . ' ' . $g['last_name']) ?>
                                </a>
                                <div class="muted" style="font-size:.76rem;"><code><?= e($g['member_no']) ?></code></div>
                            </td>
                            <td style="text-align:right;font-weight:700;"><?= e(money((float)$g['amount_guaranteed'])) ?></td>
                            <td>
                                <span class="badge badge-<?= $g['status'] === 'active' ? 'approved' : ($g['status'] === 'called' ? 'rejected' : 'info') ?>">
                                    <?= e($g['status']) ?>
                                </span>
                                <?php if ($g['status'] !== 'active' && $g['release_reason']): ?>
                                    <div class="muted" style="font-size:.72rem;margin-top:4px;">
                                        <?= e($g['release_reason']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="muted" style="font-size:.82rem;">
                                <?= e($g['added_by_name'] ?: 'System') ?>
                                <div style="font-size:.72rem;"><?= e(date('M j, Y', strtotime($g['created_at']))) ?></div>
                            </td>
                            <td style="text-align:right;">
                                <?php if ($g['status'] === 'active' && $loan['status'] === 'active'): ?>
                                    <button type="button" class="btn btn-ghost btn-sm" onclick="openRelease(<?= (int)$g['id'] ?>, '<?= e($g['first_name'] . ' ' . $g['last_name']) ?>')">Release</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="center muted" style="padding:40px;">
                <div style="font-size:2rem;margin-bottom:8px;">👥</div>
                <div style="font-weight:600;color:var(--text);">No guarantors yet</div>
                <div style="font-size:.85rem;">Add one using the form.</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add guarantor -->
    <div class="card animate-fade-up delay-1" style="align-self:flex-start;">
        <h3 class="card-title" style="margin-bottom:14px;">Add guarantor</h3>
        <?php if ($loan['status'] !== 'active'): ?>
            <div class="alert alert-info" style="margin:0;">
                Guarantors can only be added to active loans.
            </div>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="loan_id" value="<?= $loanId ?>">
                <input type="hidden" name="action" value="add">

                <label class="field">
                    <span>Member *</span>
                    <select name="member_id" required>
                        <option value="">— Select member —</option>
                        <?php foreach ($candidates as $c): ?>
                            <?php
                                $remaining = (float)$c['savings_balance'] - (float)$c['already_guaranteed'];
                                $disabled = $remaining <= 0;
                                $label = $c['first_name'] . ' ' . $c['last_name'] .
                                         ' (' . $c['member_no'] . ') — can guarantee up to ' .
                                         money(max(0, $remaining));
                            ?>
                            <option value="<?= (int)$c['id'] ?>"
                                    <?= $old['member_id'] == $c['id'] ? 'selected' : '' ?>
                                    <?= $disabled ? 'disabled' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="muted" style="font-size:.75rem;">
                        A member can only guarantee up to their savings balance.
                    </small>
                </label>

                <label class="field"><span>Amount guaranteed *</span>
                    <input type="number" step="0.01" min="0.01" name="amount_guaranteed"
                           value="<?= e($old['amount_guaranteed']) ?>" required>
                </label>

                <label class="field"><span>Notes</span>
                    <textarea name="notes" rows="2"><?= e($old['notes']) ?></textarea>
                </label>

                <button class="btn btn-primary btn-block" style="margin-top:14px;">Add guarantor</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Release modal -->
<div id="releaseModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.55);align-items:center;justify-content:center;padding:20px;">
    <form method="post" class="card" style="max-width:480px;width:100%;margin:0;" onsubmit="return confirm('Release this guarantor?');">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="loan_id" value="<?= $loanId ?>">
        <input type="hidden" name="action" value="release">
        <input type="hidden" name="guarantor_id" id="releaseGuarantorId" value="">

        <h3 class="card-title" style="margin-bottom:8px;">Release guarantor</h3>
        <p class="muted" id="releaseWho" style="font-size:.9rem;margin-bottom:14px;"></p>

        <label class="field"><span>Reason *</span>
            <textarea name="reason" rows="3" required placeholder="e.g. Loan terms changed, member requested release"></textarea>
        </label>

        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px;">
            <button type="button" class="btn btn-ghost" onclick="closeRelease()">Cancel</button>
            <button type="submit" class="btn btn-danger">Release</button>
        </div>
    </form>
</div>

<script>
function openRelease(id, name) {
    document.getElementById('releaseGuarantorId').value = id;
    document.getElementById('releaseWho').textContent = 'Releasing ' + name;
    document.getElementById('releaseModal').style.display = 'flex';
}
function closeRelease() {
    document.getElementById('releaseModal').style.display = 'none';
}


<?php require_once __DIR__ . '/../includes/footer.php'; ?>