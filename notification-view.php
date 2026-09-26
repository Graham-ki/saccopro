<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
$user = require_login();
require_once __DIR__ . '/includes/notifications.php';

$key = trim((string)($_GET['key'] ?? ''));

if ($key === '') {
    flash('error', 'Notification not found.');
    redirect('/scms/notifications.php');
}

// Rebuild the current list and find this item by key
$data = build_notifications($pdo, $user);
$item = null;
foreach ($data['items'] as $n) {
    if ($n['key'] === $key) { $item = $n; break; }
}

if (!$item) {
    flash('error', 'This notification is no longer active (it may have been resolved or dismissed).');
    redirect('/scms/notifications.php');
}

// ---- Handle POST: dismiss from the detail page ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'dismiss') {
        dismiss_notification($pdo, (int)$user['id'], $key);
        flash('success', 'Notification dismissed.');
        redirect('/scms/notifications.php');
    }
}

// ---- Extra detail per category (rendered below) ----
$details = [];

// Overdue loan
if (preg_match('/^overdue-loan-(\d+)$/', $key, $m)) {
    $stmt = $pdo->prepare("
        SELECT l.*, m.id AS member_id, m.first_name, m.last_name, m.member_no, m.phone,
               (SELECT COUNT(*) FROM loan_schedules WHERE loan_id = l.id AND status IN ('pending','partial','overdue')) AS remaining_installments
        FROM loans l
        JOIN members m ON m.id = l.member_id
        WHERE l.id = ? LIMIT 1
    ");
    $stmt->execute([(int)$m[1]]);
    $details = $stmt->fetch() ?: [];
}

// Upcoming due
if (preg_match('/^due-(\d+)$/', $key, $m)) {
    $stmt = $pdo->prepare("
        SELECT ls.*, l.loan_no, l.id AS loan_id, m.id AS member_id,
               m.first_name, m.last_name, m.member_no, m.phone
        FROM loan_schedules ls
        JOIN loans l ON l.id = ls.loan_id
        JOIN members m ON m.id = l.member_id
        WHERE ls.id = ? LIMIT 1
    ");
    $stmt->execute([(int)$m[1]]);
    $details = $stmt->fetch() ?: [];
}

// Recent activity
if (preg_match('/^recent-(savings|loan)-(\d+)$/', $key, $m)) {
    if ($m[1] === 'savings') {
        $stmt = $pdo->prepare("
            SELECT st.*, sa.account_no, m.id AS member_id, m.first_name, m.last_name, m.member_no
            FROM savings_transactions st
            JOIN savings_accounts sa ON sa.id = st.account_id
            JOIN members m ON m.id = sa.member_id
            WHERE st.id = ? LIMIT 1
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT l.*, l.loan_no, m.id AS member_id, m.first_name, m.last_name, m.member_no
            FROM loans l
            JOIN members m ON m.id = l.member_id
            WHERE l.id = ? LIMIT 1
        ");
    }
    $stmt->execute([(int)$m[2]]);
    $details = $stmt->fetch() ?: [];
}

// Pending approvals
if ($key === 'pending-approvals') {
    $details = [
        'list' => $pdo->query("
            SELECT id, full_name, username, email, created_at
            FROM users WHERE status='pending'
            ORDER BY created_at ASC
        ")->fetchAll(),
    ];
}

$pageTitle = 'Notification';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Notification</h1>
        <p>Detail view.</p>
    </div>
    <div class="page-head-actions">
        <a href="notifications.php" class="btn btn-ghost">← All notifications</a>
        <a href="<?= e($item['url']) ?>" class="btn btn-primary">Open related page →</a>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<div class="card animate-fade-up" style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;">
    <div class="notif-icon" style="width:56px;height:56px;font-size:1.4rem;border-radius:14px;display:grid;place-items:center;background:var(--primary-l);color:var(--primary);flex-shrink:0;">
        <?= e($item['icon']) ?>
    </div>
    <div style="flex:1;min-width:240px;">
        <div style="font-size:1.15rem;font-weight:700;"><?= e($item['title']) ?></div>
        <div class="muted" style="margin-top:4px;"><?= e($item['text']) ?></div>
        <div class="muted" style="font-size:.82rem;margin-top:8px;">
            <span class="badge badge-info"><?= e($item['category'] ?? 'general') ?></span>
            &nbsp;·&nbsp; <?= e($item['time']) ?>
            <?php if (!empty($item['unread'])): ?>
                &nbsp;·&nbsp; <span class="badge badge-pending">Unread</span>
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-shrink:0;">
        <form method="post" onsubmit="return confirm('Dismiss this notification?');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="dismiss">
            <button class="btn btn-outline btn-sm">Dismiss</button>
        </form>
    </div>
</div>

<?php /* --------- Detail panels per category --------- */ ?>

<?php /* Overdue loan */ ?>
<?php if (preg_match('/^overdue-loan-/', $key) && $details): ?>
    <div class="card animate-fade-up delay-1" style="margin-top:18px;">
        <div class="card-title" style="margin-bottom:14px;">Loan details</div>
        <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px 24px;font-size:.9rem;">
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Loan</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><code><?= e($details['loan_no']) ?></code></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Member</dt>
                <dd style="margin:4px 0 0;font-weight:600;">
                    <a href="/scms/members/view.php?id=<?= (int)$details['member_id'] ?>">
                        <?= e($details['first_name'] . ' ' . $details['last_name']) ?>
                    </a>
                </dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Phone</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= e($details['phone'] ?: '—') ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Maturity</dt>
                <dd style="margin:4px 0 0;font-weight:600;color:var(--danger);"><?= e(date('M j, Y', strtotime($details['maturity_date']))) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Balance</dt>
                <dd style="margin:4px 0 0;font-weight:700;"><?= e(money((float)$details['balance'])) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Remaining installments</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= (int)$details['remaining_installments'] ?></dd>
            </div>
        </dl>
        <div style="margin-top:16px;">
            <a href="/scms/loans/view.php?id=<?= (int)$details['id'] ?>" class="btn btn-primary btn-sm">Open loan</a>
            <a href="/scms/loans/repay.php?loan_id=<?= (int)$details['id'] ?>" class="btn btn-outline btn-sm">Record repayment</a>
        </div>
    </div>
<?php endif; ?>

<?php /* Upcoming due */ ?>
<?php if (preg_match('/^due-/', $key) && $details): ?>
    <?php $outstanding = max(0, (float)$details['total_due'] - (float)$details['amount_paid']); ?>
    <div class="card animate-fade-up delay-1" style="margin-top:18px;">
        <div class="card-title" style="margin-bottom:14px;">Installment details</div>
        <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px 24px;font-size:.9rem;">
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Loan</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><code><?= e($details['loan_no']) ?></code></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Member</dt>
                <dd style="margin:4px 0 0;font-weight:600;">
                    <a href="/scms/members/view.php?id=<?= (int)$details['member_id'] ?>">
                        <?= e($details['first_name'] . ' ' . $details['last_name']) ?>
                    </a>
                </dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Installment</dt>
                <dd style="margin:4px 0 0;font-weight:600;">#<?= (int)$details['installment_no'] ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Due date</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= e(date('M j, Y', strtotime($details['due_date']))) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Outstanding</dt>
                <dd style="margin:4px 0 0;font-weight:700;"><?= e(money($outstanding)) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Status</dt>
                <dd style="margin:4px 0 0;">
                    <span class="badge badge-<?= $details['status'] === 'overdue' ? 'rejected' : ($details['status'] === 'partial' ? 'pending' : 'info') ?>">
                        <?= e($details['status']) ?>
                    </span>
                </dd>
            </div>
        </dl>
        <div style="margin-top:16px;">
            <a href="/scms/loans/repay.php?loan_id=<?= (int)$details['loan_id'] ?>" class="btn btn-primary btn-sm">Record repayment</a>
            <a href="/scms/loans/view.php?id=<?= (int)$details['loan_id'] ?>" class="btn btn-outline btn-sm">Open loan</a>
        </div>
    </div>
<?php endif; ?>

<?php /* Recent savings */ ?>
<?php if (preg_match('/^recent-savings-/', $key) && $details): ?>
    <div class="card animate-fade-up delay-1" style="margin-top:18px;">
        <div class="card-title" style="margin-bottom:14px;">Transaction details</div>
        <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px 24px;font-size:.9rem;">
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Reference</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><code><?= e($details['reference']) ?></code></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Member</dt>
                <dd style="margin:4px 0 0;font-weight:600;">
                    <a href="/scms/members/view.php?id=<?= (int)$details['member_id'] ?>">
                        <?= e($details['first_name'] . ' ' . $details['last_name']) ?>
                    </a>
                </dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Type</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= e(ucfirst($details['type'])) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Amount</dt>
                <dd style="margin:4px 0 0;font-weight:800;color:<?= $details['type'] === 'deposit' ? 'var(--accent)' : 'var(--danger)' ?>;">
                    <?= e(money((float)$details['amount'])) ?>
                </dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Date</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= e(date('M j, Y', strtotime($details['transaction_date']))) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Account</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><code><?= e($details['account_no']) ?></code></dd>
            </div>
        </dl>
        <div style="margin-top:16px;">
            <a href="/scms/savings/receipt.php?txn_id=<?= (int)$details['id'] ?>" class="btn btn-primary btn-sm" target="_blank">🧾 Print receipt</a>
            <a href="/scms/savings/statement.php?account_id=<?= (int)$details['account_id'] ?>" class="btn btn-outline btn-sm">Statement</a>
        </div>
    </div>
<?php endif; ?>

<?php /* Recent loan issued */ ?>
<?php if (preg_match('/^recent-loan-/', $key) && $details): ?>
    <div class="card animate-fade-up delay-1" style="margin-top:18px;">
        <div class="card-title" style="margin-bottom:14px;">Loan details</div>
        <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px 24px;font-size:.9rem;">
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Loan</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><code><?= e($details['loan_no']) ?></code></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Member</dt>
                <dd style="margin:4px 0 0;font-weight:600;">
                    <a href="/scms/members/view.php?id=<?= (int)$details['member_id'] ?>">
                        <?= e($details['first_name'] . ' ' . $details['last_name']) ?>
                    </a>
                </dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Principal</dt>
                <dd style="margin:4px 0 0;font-weight:700;"><?= e(money((float)$details['principal'])) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Issued</dt>
                <dd style="margin:4px 0 0;font-weight:600;"><?= e(date('M j, Y', strtotime($details['issue_date']))) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Balance</dt>
                <dd style="margin:4px 0 0;font-weight:700;"><?= e(money((float)$details['balance'])) ?></dd>
            </div>
            <div>
                <dt class="muted" style="font-size:.75rem;text-transform:uppercase;">Status</dt>
                <dd style="margin:4px 0 0;">
                    <span class="badge badge-<?= $details['status'] === 'active' ? 'info' : ($details['status'] === 'completed' ? 'approved' : 'rejected') ?>">
                        <?= e($details['status']) ?>
                    </span>
                </dd>
            </div>
        </dl>
        <div style="margin-top:16px;">
            <a href="/scms/loans/view.php?id=<?= (int)$details['id'] ?>" class="btn btn-primary btn-sm">Open loan</a>
        </div>
    </div>
<?php endif; ?>

<?php /* Pending approvals */ ?>
<?php if ($key === 'pending-approvals' && !empty($details['list'])): ?>
    <div class="card animate-fade-up delay-1" style="margin-top:18px;">
        <div class="card-title" style="margin-bottom:14px;">Pending users</div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Name</th><th>Username</th><th>Email</th><th>Registered</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($details['list'] as $u): ?>
                    <tr>
                        <td><strong><?= e($u['full_name']) ?></strong></td>
                        <td><code><?= e($u['username']) ?></code></td>
                        <td class="muted"><?= e($u['email']) ?></td>
                        <td class="muted"><?= e(date('M j, Y', strtotime($u['created_at']))) ?></td>
                        <td style="text-align:right;">
                            <a href="/scms/admin/users.php?status=pending" class="btn btn-outline btn-sm">Review</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>