<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/_helpers.php';

[$from, $to] = report_range();

// ---- Range totals (effective only: exclude reversed + reversal rows) ----
$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN type='deposit'    THEN amount ELSE 0 END), 0) AS deposits,
        COALESCE(SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END), 0) AS withdrawals,
        COUNT(CASE WHEN type='deposit'    THEN 1 END)                        AS dep_count,
        COUNT(CASE WHEN type='withdrawal' THEN 1 END)                        AS wd_count,
        COUNT(CASE WHEN is_reversed = 1 THEN 1 END)                           AS reversed_count
    FROM savings_transactions
    WHERE transaction_date BETWEEN :from1 AND :to1
      AND is_reversed = 0
      AND (reversal_of_id IS NULL OR reversal_of_id = 0)
");
$stmt->execute([':from1' => $from, ':to1' => $to]);
$s = $stmt->fetch();
$stmt->closeCursor();

$net = (float)$s['deposits'] - (float)$s['withdrawals'];

// ---- Overall (still raw — the account balance is always the truth) ----
$overall = $pdo->query("
    SELECT COUNT(*) AS accounts,
           COALESCE(SUM(balance), 0) AS total,
           COALESCE(AVG(balance), 0) AS avg_bal
    FROM savings_accounts
")->fetch();

// ---- Monthly flow (effective only) ----
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS ym,
           SUM(CASE WHEN type='deposit'    THEN amount ELSE 0 END) AS dep,
           SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END) AS wd,
           COUNT(CASE WHEN is_reversed = 1 THEN 1 END)              AS reversed_n
    FROM savings_transactions
    WHERE transaction_date BETWEEN :from1 AND :to1
      AND is_reversed = 0
      AND (reversal_of_id IS NULL OR reversal_of_id = 0)
    GROUP BY ym ORDER BY ym
");
$stmt->execute([':from1' => $from, ':to1' => $to]);
$monthly = $stmt->fetchAll();
$stmt->closeCursor();

// ---- Largest effective transactions ----
$stmt = $pdo->prepare("
    SELECT st.*, m.first_name, m.last_name, m.id AS member_id, sa.account_no
    FROM savings_transactions st
    JOIN savings_accounts sa ON sa.id = st.account_id
    JOIN members m ON m.id = sa.member_id
    WHERE st.transaction_date BETWEEN :from1 AND :to1
      AND st.is_reversed = 0
      AND (st.reversal_of_id IS NULL OR st.reversal_of_id = 0)
    ORDER BY st.amount DESC
    LIMIT 10
");
$stmt->execute([':from1' => $from, ':to1' => $to]);
$largest = $stmt->fetchAll();
$stmt->closeCursor();

// ---- Reversed transactions in this range (for the audit panel) ----
$stmt = $pdo->prepare("
    SELECT st.*, m.first_name, m.last_name, m.id AS member_id, sa.account_no,
           u.full_name AS reversed_by_name
    FROM savings_transactions st
    JOIN savings_accounts sa ON sa.id = st.account_id
    JOIN members m ON m.id = sa.member_id
    LEFT JOIN users u ON u.id = st.reversed_by_id
    WHERE st.transaction_date BETWEEN :from1 AND :to1
      AND st.is_reversed = 1
    ORDER BY st.transaction_date DESC, st.id DESC
");
$stmt->execute([':from1' => $from, ':to1' => $to]);
$reversed = $stmt->fetchAll();
$stmt->closeCursor();

$pageTitle = 'Savings Report';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
@media print {
    .sidebar,.topbar,.page-head-actions,.tabs,form.card { display:none !important; }
    .main { margin-left:0 !important; } .page { padding:0 !important; }
    .card,.table-wrap { box-shadow:none !important; border-color:#ccc !important; }
}
</style>
<div class="page-head">
    <div>
        <h1>Savings report</h1>
        <p><?= e(date('M j, Y', strtotime($from))) ?> → <?= e(date('M j, Y', strtotime($to))) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="export.php?report=savings&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-outline">⬇ CSV</a>
    </div>
</div>

<nav class="tabs" style="margin-bottom:18px;">
    <a href="index.php" class="tab">Overview</a>
    <a href="members.php" class="tab">Members</a>
    <a href="savings.php" class="tab active">Savings</a>
    <a href="loans.php" class="tab">Loans</a>
    <a href="income.php" class="tab">Income</a>
</nav>

<form method="get" class="card" style="padding:14px 18px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:18px;">
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        From
        <input type="date" name="from" value="<?= e($from) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        To
        <input type="date" name="to" value="<?= e($to) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </label>
    <button class="btn btn-outline btn-sm" type="submit" style="align-self:flex-end;">Apply</button>
</form>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
    <div class="stat animate-fade-up">
        <div class="stat-label">Total savings held</div>
        <div class="stat-value"><?= e(money((float)$overall['total'])) ?></div>
        <div class="stat-trend muted"><?= (int)$overall['accounts'] ?> accounts</div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-label">Deposits (effective)</div>
        <div class="stat-value" style="color:var(--accent);"><?= e(money((float)$s['deposits'])) ?></div>
        <div class="stat-trend muted"><?= (int)$s['dep_count'] ?> transactions</div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-label">Withdrawals (effective)</div>
        <div class="stat-value" style="color:var(--danger);"><?= e(money((float)$s['withdrawals'])) ?></div>
        <div class="stat-trend muted"><?= (int)$s['wd_count'] ?> transactions</div>
    </div>
    <div class="stat animate-fade-up delay-3">
        <div class="stat-label">Net flow</div>
        <div class="stat-value" style="color:<?= $net >= 0 ? 'var(--accent)' : 'var(--danger)' ?>;">
            <?= e(money($net)) ?>
        </div>
        <?php if ((int)$s['reversed_count'] > 0): ?>
            <div class="stat-trend muted">
                <?= (int)$s['reversed_count'] ?> reversed excluded
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Monthly flow -->
<div class="card animate-fade-up delay-4" style="margin-top:18px;">
    <div class="card-title" style="margin-bottom:14px;">Monthly flow (effective)</div>
    <?php if ($monthly): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th style="text-align:right;">Deposits</th>
                        <th style="text-align:right;">Withdrawals</th>
                        <th style="text-align:right;">Net</th>
                        <th style="text-align:right;">Reversed</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $tDep = 0; $tWd = 0; $tRev = 0;
                foreach ($monthly as $m):
                    $n = (float)$m['dep'] - (float)$m['wd'];
                    $tDep += (float)$m['dep'];
                    $tWd  += (float)$m['wd'];
                    $tRev += (int)$m['reversed_n'];
                ?>
                    <tr>
                        <td><?= e(date('F Y', strtotime($m['ym'] . '-01'))) ?></td>
                        <td style="text-align:right;color:var(--accent);">+<?= e(money((float)$m['dep'])) ?></td>
                        <td style="text-align:right;color:var(--danger);">−<?= e(money((float)$m['wd'])) ?></td>
                        <td style="text-align:right;font-weight:600;color:<?= $n >= 0 ? 'var(--accent)' : 'var(--danger)' ?>;">
                            <?= e(money($n)) ?>
                        </td>
                        <td style="text-align:right;" class="muted">
                            <?= (int)$m['reversed_n'] ?: '—' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="background:var(--surface-2);border-top:2px solid var(--border);">
                        <td style="font-weight:700;">Total</td>
                        <td style="text-align:right;font-weight:700;color:var(--accent);">+<?= e(money($tDep)) ?></td>
                        <td style="text-align:right;font-weight:700;color:var(--danger);">−<?= e(money($tWd)) ?></td>
                        <td style="text-align:right;font-weight:800;"><?= e(money($tDep - $tWd)) ?></td>
                        <td style="text-align:right;font-weight:700;" class="muted"><?= $tRev ?: '—' ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="center muted" style="padding:32px;">No effective activity in this range.</div>
    <?php endif; ?>
</div>

<!-- Largest effective transactions -->
<div class="card animate-fade-up delay-5" style="margin-top:18px;">
    <div class="card-title" style="margin-bottom:14px;">Largest transactions (effective)</div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Date</th><th>Member</th><th>Type</th><th style="text-align:right;">Amount</th><th>Reference</th></tr>
            </thead>
            <tbody>
            <?php foreach ($largest as $r): ?>
                <tr>
                    <td><?= e(date('M j, Y', strtotime($r['transaction_date']))) ?></td>
                    <td><a href="/scms/members/view.php?id=<?= (int)$r['member_id'] ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></a></td>
                    <td>
                        <span class="badge badge-<?= $r['type'] === 'deposit' ? 'approved' : 'rejected' ?>">
                            <?= e(ucfirst($r['type'])) ?>
                        </span>
                    </td>
                    <td style="text-align:right;font-weight:600;"><?= e(money((float)$r['amount'])) ?></td>
                    <td><code style="font-size:.78rem;"><?= e($r['reference']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$largest): ?>
                <tr><td colspan="5" class="center muted" style="padding:32px;">No effective transactions.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Reversed transactions (audit panel) -->
<?php if ($reversed): ?>
    <div class="card animate-fade-up" style="margin-top:18px;border-color:rgba(239,68,68,.35);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
            <div>
                <div class="card-title" style="color:var(--danger);">⚠️ Reversed transactions in this range</div>
                <div class="card-sub">
                    These are excluded from the totals above. Shown here for the audit trail.
                </div>
            </div>
            <span class="badge badge-rejected"><?= count($reversed) ?> reversed</span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Member</th>
                        <th>Account</th>
                        <th>Type</th>
                        <th style="text-align:right;">Amount</th>
                        <th>Reference</th>
                        <th>Reversed by</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reversed as $r): ?>
                    <tr style="opacity:.85;">
                        <td><?= e(date('M j, Y', strtotime($r['transaction_date']))) ?></td>
                        <td>
                            <a href="/scms/members/view.php?id=<?= (int)$r['member_id'] ?>">
                                <?= e($r['first_name'] . ' ' . $r['last_name']) ?>
                            </a>
                        </td>
                        <td><code style="font-size:.8rem;"><?= e($r['account_no']) ?></code></td>
                        <td>
                            <span class="badge badge-<?= $r['type'] === 'deposit' ? 'approved' : 'rejected' ?>">
                                <?= e(ucfirst($r['type'])) ?>
                            </span>
                        </td>
                        <td style="text-align:right;font-weight:600;text-decoration:line-through;opacity:.7;">
                            <?= e(money((float)$r['amount'])) ?>
                        </td>
                        <td><code style="font-size:.78rem;"><?= e($r['reference']) ?></code></td>
                        <td class="muted" style="font-size:.82rem;">
                            <?= e($r['reversed_by_name'] ?: 'System') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted" style="font-size:.8rem;margin-top:12px;">
            A reversed transaction and its counter-entry have a net effect of zero.
            They are omitted from deposits, withdrawals, net flow, and monthly totals.
            The account balance is always correct because each reversal posts a
            compensating ledger entry atomically.
        </p>
    </div>
<?php endif; ?>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>