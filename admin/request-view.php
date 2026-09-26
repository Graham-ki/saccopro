<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_admin();
require_once __DIR__ . '/../includes/requests.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT r.*, m.id AS member_id, m.first_name, m.last_name, m.member_no, m.phone,
           sa.balance AS savings_balance,
           u.full_name AS decided_by_name
    FROM member_requests r
    JOIN members m ON m.id = r.member_id
    LEFT JOIN savings_accounts sa ON sa.member_id = m.id
    LEFT JOIN users u ON u.id = r.decided_by
    WHERE r.id = ? LIMIT 1
");
$stmt->execute([$id]);
$req = $stmt->fetch();

if (!$req) {
    flash('error', 'Request not found.');
    redirect('/scms/admin/requests.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        try {
            $result = approve_member_request($pdo, $id, (int)$user['id']);
            audit_log($pdo, (int)$user['id'], 'request.approve', 'member_request', $id,
                $result['summary']);
            flash('success', 'Approved. ' . $result['summary']);
            redirect('/scms/admin/request-view.php?id=' . $id);
        } catch (Throwable $ex) {
            error_log('[request-view] ' . $ex->getMessage());
            $errors[] = $ex->getMessage();
        }
    }

    if ($action === 'reject') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        try {
            reject_member_request($pdo, $id, (int)$user['id'], $reason);
            audit_log($pdo, (int)$user['id'], 'request.reject', 'member_request', $id, $reason);
            flash('success', 'Request rejected.');
            redirect('/scms/admin/request-view.php?id=' . $id);
        } catch (Throwable $ex) {
            $errors[] = $ex->getMessage();
        }
    }
}

// Eligibility warnings for pending approvals
$warnings = [];
if ($req['status'] === 'pending') {
    if ($req['type'] === 'withdrawal' && (float)$req['amount'] > (float)$req['savings_balance']) {
        $warnings[] = 'Requested withdrawal (' . money((float)$req['amount']) .
                      ') exceeds savings balance (' . money((float)$req['savings_balance']) . ').';
    }
    if ($req['type'] === 'share_purchase') {
        $faceValue = (float)(setting('share_face_value') ?? '10000');
        $total = (int)$req['qty'] * $faceValue;
        if ($total > (float)$req['savings_balance']) {
            $warnings[] = 'Share purchase cost (' . money($total) .
                          ') exceeds savings balance (' . money((float)$req['savings_balance']) . ').';
        }
    }
}

$pageTitle = 'Request #' . $id;
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.dash-grid { display:grid; grid-template-columns:1.4fr 1fr; gap:18px; }
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); margin-bottom:14px; }
.field textarea {
    padding:11px 12px; border:1.5px solid var(--border); border-radius:8px;
    background:var(--surface); color:var(--text); font:inherit; font-size:.95rem;
}
.field textarea:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,.12); }
@media (max-width:900px) { .dash-grid { grid-template-columns:1fr; } }
</style>
<div class="page-head">
    <div>
        <h1>Request #<?= $id ?></h1>
        <p>
            <?= e(ucfirst(str_replace('_',' ', $req['type']))) ?>
            · from <?= e($req['first_name'] . ' ' . $req['last_name']) ?>
            · submitted <?= e(date('M j, Y g:ia', strtotime($req['created_at']))) ?>
        </p>
    </div>
    <div class="page-head-actions">
        <a href="requests.php" class="btn btn-ghost">← All requests</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <strong>Error:</strong>
        <ul style="margin-top:8px;">
            <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>
<?php foreach ($warnings as $w): ?>
    <div class="alert alert-warning">⚠️ <?= e($w) ?></div>
<?php endforeach; ?>

<div class="dash-grid">

    <div class="card animate-fade-up">
        <h3 class="card-title" style="margin-bottom:14px;">Details</h3>
        <dl style="display:grid;grid-template-columns:1fr 1fr;gap:16px 24px;font-size:.9rem;">
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Status</dt>
                <dd style="margin:4px 0 0;">
                    <span class="badge badge-<?=
                        $req['status'] === 'approved' ? 'approved' :
                        ($req['status'] === 'rejected' ? 'rejected' :
                        ($req['status'] === 'cancelled' ? 'suspended' : 'pending')) ?>">
                        <?= e($req['status']) ?>
                    </span>
                </dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Type</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= e(ucfirst(str_replace('_',' ', $req['type']))) ?></dd>
            </div>

            <?php if ($req['type'] === 'share_purchase'): ?>
                <div>
                    <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Quantity</dt>
                    <dd style="margin:4px 0 0;font-weight:600;"><?= (int)$req['qty'] ?> shares</dd>
                </div>
                <div>
                    <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Face value</dt>
                    <dd style="margin:4px 0 0;font-weight:600;"><?= e(money((float)(setting('share_face_value') ?? '10000'))) ?></dd>
                </div>
            <?php else: ?>
                <div>
                    <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Amount</dt>
                    <dd style="margin:4px 0 0;font-weight:800;"><?= e(money((float)$req['amount'])) ?></dd>
                </div>
            <?php endif; ?>

            <?php if ($req['type'] === 'loan'): ?>
                <div>
                    <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Term</dt>
                    <dd style="margin:4px 0 0;font-weight:600;"><?= (int)$req['term_months'] ?> months</dd>
                </div>
                <div>
                    <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Interest</dt>
                    <dd style="margin:4px 0 0;font-weight:600;">
                        <?= e(number_format((float)$req['interest_rate'], 2)) ?>%/mo · <?= e($req['interest_method']) ?>
                    </dd>
                </div>
            <?php endif; ?>

            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Member</dt>
                <dd style="margin:4px 0 0;font-weight:600;">
                    <a href="/scms/members/view.php?id=<?= (int)$req['member_id'] ?>">
                        <?= e($req['first_name'] . ' ' . $req['last_name']) ?>
                    </a>
                </dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Savings balance</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= e(money((float)$req['savings_balance'])) ?></dd>
            </div>

            <?php if ($req['purpose']): ?>
                <div style="grid-column:1 / -1;">
                    <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Purpose</dt>
                    <dd style="margin:4px 0 0;"><?= e($req['purpose']) ?></dd>
                </div>
            <?php endif; ?>

            <?php if ($req['notes']): ?>
                <div style="grid-column:1 / -1;">
                    <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Notes</dt>
                    <dd style="margin:4px 0 0;"><?= e($req['notes']) ?></dd>
                </div>
            <?php endif; ?>

            <?php if ($req['decided_at']): ?>
                <div>
                    <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Decided by</dt>
                    <dd style="margin:4px 0 0;font-weight:600;"><?= e($req['decided_by_name'] ?: '—') ?></dd>
                </div>
                <div>
                    <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Decided at</dt>
                    <dd style="margin:4px 0 0;font-weight:600;"><?= e(date('M j, Y g:ia', strtotime($req['decided_at']))) ?></dd>
                </div>
                <?php if ($req['decision_reason']): ?>
                    <div style="grid-column:1 / -1;">
                        <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Reason</dt>
                        <dd style="margin:4px 0 0;"><?= e($req['decision_reason']) ?></dd>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </dl>
    </div>

    <!-- Actions panel -->
    <div class="card animate-fade-up delay-1" style="align-self:flex-start;">
        <h3 class="card-title" style="margin-bottom:12px;">Actions</h3>

        <?php if ($req['status'] !== 'pending'): ?>
            <div class="alert alert-info" style="margin:0;">
                This request has been <strong><?= e($req['status']) ?></strong>.
                <?php if ($req['resulting_entity_id']): ?>
                    <div style="margin-top:8px;font-size:.82rem;">
                        Linked entity #<?= (int)$req['resulting_entity_id'] ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>

            <form method="post" style="margin-bottom:14px;"
                  onsubmit="return confirm('Approve this request? The action will be posted immediately.');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="approve">
                <button class="btn btn-success btn-block" <?= $warnings ? 'disabled' : '' ?>>
                    ✓ Approve &amp; post
                </button>
                <?php if ($warnings): ?>
                    <p class="muted" style="font-size:.78rem;margin-top:6px;text-align:center;">
                        Fix the warnings above before approving.
                    </p>
                <?php endif; ?>
            </form>

            <form method="post" onsubmit="return confirm('Reject this request?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="reject">

                <label class="field">
                    <span>Rejection reason *</span>
                    <textarea name="reason" rows="3" required placeholder="Explain why"></textarea>
                </label>

                <button class="btn btn-danger btn-block">✕ Reject</button>
            </form>

        <?php endif; ?>
    </div>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>