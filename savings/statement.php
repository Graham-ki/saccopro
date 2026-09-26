<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();

$accountId = (int)($_GET['account_id'] ?? 0);
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

// Validate dates
$valid = fn(string $d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$valid($from)) $from = date('Y-m-01');
if (!$valid($to))   $to   = date('Y-m-d');
if (strtotime($from) > strtotime($to)) [$from, $to] = [$to, $from];

$stmt = $pdo->prepare("
    SELECT sa.*, m.id AS member_id, m.first_name, m.last_name, m.member_no, m.phone, m.email
    FROM savings_accounts sa
    JOIN members m ON m.id = sa.member_id
    WHERE sa.id = ? LIMIT 1
");
$stmt->execute([$accountId]);
$account = $stmt->fetch();

if (!$account) {
    flash('error', 'Savings account not found.');
    redirect('/scms/savings/index.php');
}

/**
 * Rebuild a statement from raw ledger entries, excluding reversed rows
 * and their counter-entries. Returns:
 *   opening   — effective balance at start of $from
 *   closing   — effective balance at end of $to
 *   rows      — only the *effective* rows in range (reversed + reversals hidden)
 *   reversed  — raw reversed/reversal rows in range (shown muted, for audit)
 *   totals    — effective deposits/withdrawals in range
 */
function build_effective_statement(
    PDO $pdo,
    int $accountId,
    string $from,
    string $to
): array {
    // 1. Fetch ALL transactions up to $to, oldest first — includes reversed + reversals
    $stmt = $pdo->prepare("
        SELECT st.*, u.full_name AS recorded_by_name
        FROM savings_transactions st
        LEFT JOIN users u ON u.id = st.recorded_by
        WHERE st.account_id = ? AND st.transaction_date <= ?
        ORDER BY st.transaction_date ASC, st.id ASC
    ");
    $stmt->execute([$accountId, $to]);
    $all = $stmt->fetchAll();

    // 2. Walk them all, keeping a running effective balance
    $effectiveBalance = 0.0;   // before any transactions (opening of history)
    $opening = 0.0;
    $openingCaptured = false;

    $effectiveRows = [];   // in-range effective transactions
    $rawRows       = [];   // in-range raw (audit view)
    $totals = ['deposits' => 0.0, 'withdrawals' => 0.0];

    // Build lookup sets for O(1) membership tests
    // A row is "non-effective" if:
    //   - is_reversed = 1 (its counter entry cancels it), OR
    //   - reversal_of_id > 0 (it's the counter entry itself)
    foreach ($all as $t) {
        $isReversed   = (int)$t['is_reversed'] === 1;
        $isReversal   = (int)$t['reversal_of_id'] > 0;
        $isEffective  = !$isReversed && !$isReversal;

        // Signed effect on the effective balance
        $signed = $t['type'] === 'deposit'
            ?  (float)$t['amount']
            : -(float)$t['amount'];

        // Capture opening balance just before we hit the first in-range row
        if (!$openingCaptured && $t['transaction_date'] >= $from) {
            $opening = $effectiveBalance;
            $openingCaptured = true;
        }

        // Only effective rows change the effective running balance
        if ($isEffective) {
            $effectiveBalance += $signed;
        }

        // Are we in range?
        if ($t['transaction_date'] >= $from && $t['transaction_date'] <= $to) {
            $rawRows[] = $t;

            if ($isEffective) {
                $effectiveRows[] = $t + ['effective_balance' => $effectiveBalance];

                if ($t['type'] === 'deposit') {
                    $totals['deposits'] += (float)$t['amount'];
                } else {
                    $totals['withdrawals'] += (float)$t['amount'];
                }
            }
        }
    }

    // If no in-range rows, opening = effectiveBalance up to $to (which equals end)
    if (!$openingCaptured) {
        $opening = $effectiveBalance;
    }

    $closing = $opening + $totals['deposits'] - $totals['withdrawals'];

    return [
        'opening'        => $opening,
        'closing'        => $closing,
        'effective_rows' => $effectiveRows,
        'raw_rows'       => $rawRows,
        'totals'         => $totals,
    ];
}

$stmtResult = build_effective_statement($pdo, $accountId, $from, $to);
$openingBalance = $stmtResult['opening'];
$closingBalance = $stmtResult['closing'];
$rows           = $stmtResult['raw_rows'];       // all in range (audit)
$totals         = $stmtResult['totals'];

$initial = strtoupper(substr($account['first_name'], 0, 1) . substr($account['last_name'], 0, 1));

$pageTitle = 'Savings Statement';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
@media print {
    .sidebar, .topbar, .page-head-actions, .sidebar-backdrop, .alert, form.card { display: none !important; }
    .main { margin-left: 0 !important; }
    .page { padding: 0 !important; }
    .card, .table-wrap { box-shadow: none !important; border-color: #ccc !important; }
    tr[style*="opacity"] { opacity: 1 !important; }
}
</style>
<div class="page-head">
    <div>
        <h1>Savings statement</h1>
        <p><?= e($account['first_name'] . ' ' . $account['last_name']) ?> · <code><?= e($account['account_no']) ?></code></p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Savings</a>
        <button onclick="window.print()" class="btn btn-outline">🖨️ Print</button>
        <a href="deposit.php?account_id=<?= $accountId ?>" class="btn btn-primary">+ Deposit</a>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<form method="get" class="card" style="padding:14px 18px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:18px;">
    <input type="hidden" name="account_id" value="<?= (int)$accountId ?>">
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        From
        <input type="date" name="from" value="<?= e($from) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600;color:var(--text-2);">
        To
        <input type="date" name="to" value="<?= e($to) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;">
    </label>
    <button class="btn btn-outline btn-sm" type="submit" style="align-self:flex-end;">Apply</button>
    <div style="margin-left:auto;display:flex;gap:8px;align-self:flex-end;">
        <a href="<?= e(qs(['from' => date('Y-m-01'), 'to' => date('Y-m-d')])) ?>" class="btn btn-ghost btn-sm">This month</a>
        <a href="<?= e(qs(['from' => date('Y-01-01'), 'to' => date('Y-m-d')])) ?>" class="btn btn-ghost btn-sm">This year</a>
    </div>
</form>

<div class="card animate-fade-up" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
    <div style="display:flex;align-items:center;gap:16px;">
        <div class="avatar" style="width:56px;height:56px;font-size:1.2rem;border-radius:14px;"><?= e($initial) ?></div>
        <div>
            <div style="font-weight:700;font-size:1.05rem;"><?= e($account['first_name'] . ' ' . $account['last_name']) ?></div>
            <div class="muted" style="font-size:.85rem;">
                <code><?= e($account['member_no']) ?></code>
                · <code><?= e($account['account_no']) ?></code>
            </div>
            <div class="muted" style="font-size:.8rem;margin-top:4px;">
                <?= e($account['phone'] ?: '') ?><?= $account['email'] ? ' · ' . e($account['email']) : '' ?>
            </div>
        </div>
    </div>
    <div style="text-align:right;">
        <div class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;">Current balance</div>
        <div style="font-size:1.8rem;font-weight:800;"><?= e(money((float)$account['balance'])) ?></div>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-top:18px;">
    <div class="stat animate-fade-up">
        <div class="stat-label">Opening balance</div>
        <div class="stat-value" style="font-size:1.3rem;"><?= e(money($openingBalance)) ?></div>
    </div>
    <div class="stat animate-fade-up delay-1">
        <div class="stat-label">Deposits (effective)</div>
        <div class="stat-value" style="font-size:1.3rem;color:var(--accent);">+<?= e(money($totals['deposits'])) ?></div>
    </div>
    <div class="stat animate-fade-up delay-2">
        <div class="stat-label">Withdrawals (effective)</div>
        <div class="stat-value" style="font-size:1.3rem;color:var(--danger);">−<?= e(money($totals['withdrawals'])) ?></div>
    </div>
    <div class="stat animate-fade-up delay-3">
        <div class="stat-label">Closing balance</div>
        <div class="stat-value" style="font-size:1.3rem;"><?= e(money($closingBalance)) ?></div>
    </div>
</div>

<div class="table-wrap animate-fade-in" style="margin-top:18px;">
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Type</th>
                <th style="text-align:right;">Amount</th>
                <th style="text-align:right;">Balance</th>
                <th>Status</th>
                <th>Notes</th>
                <th>By</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <tr style="background:var(--surface-2);">
                <td colspan="4"><em class="muted">Opening balance</em></td>
                <td style="text-align:right;font-weight:700;"><?= e(money($openingBalance)) ?></td>
                <td colspan="4"></td>
            </tr>

            <?php foreach ($rows as $r): ?>
                <?php
                    $isReversed = (int)$r['is_reversed'] === 1;
                    $isReversal = (int)$r['reversal_of_id'] > 0;
                    $muted = $isReversed || $isReversal;
                ?>
                <tr style="<?= $muted ? 'opacity:.55;' : '' ?>">
                    <td style="white-space:nowrap;"><?= e(date('M j, Y', strtotime($r['transaction_date']))) ?></td>
                    <td>
                        <code style="font-size:.78rem;"><?= e($r['reference']) ?></code>
                        <?php if ($isReversal): ?>
                            <div class="muted" style="font-size:.7rem;">reverse of #<?= (int)$r['reversal_of_id'] ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['type'] === 'deposit'): ?>
                            <span class="badge badge-approved">Deposit</span>
                        <?php else: ?>
                            <span class="badge badge-rejected">Withdrawal</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;font-weight:600;color:<?= $r['type'] === 'deposit' ? 'var(--accent)' : 'var(--danger)' ?>;<?= $muted ? 'text-decoration:line-through;' : '' ?>">
                        <?= $r['type'] === 'deposit' ? '+' : '−' ?><?= e(money((float)$r['amount'])) ?>
                    </td>
                    <td style="text-align:right;<?= $muted ? 'text-decoration:line-through;opacity:.6;' : '' ?>">
                        <?= e(money((float)$r['balance_after'])) ?>
                    </td>
                    <td>
                        <?php if ($isReversed): ?>
                            <span class="badge badge-rejected">Reversed</span>
                        <?php elseif ($isReversal): ?>
                            <span class="badge badge-pending">Reversal</span>
                        <?php else: ?>
                            <span class="badge badge-approved">Posted</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted" style="font-size:.82rem;max-width:180px;"><?= e($r['notes'] ?: '—') ?></td>
                    <td class="muted" style="font-size:.82rem;"><?= e($r['recorded_by_name'] ?: 'System') ?></td>
                    <td style="text-align:right;">
                        <?php if (!$muted): ?>
                            <a href="/scms/savings/receipt.php?txn_id=<?= (int)$r['id'] ?>" class="btn btn-ghost btn-sm" title="Receipt" target="_blank">🧾</a>
                            <a href="/scms/savings/reverse.php?txn_id=<?= (int)$r['id'] ?>" class="btn btn-ghost btn-sm" title="Reverse">↩</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$rows): ?>
                <tr><td colspan="9" class="center muted" style="padding:40px;">
                    No transactions in the selected period.
                </td></tr>
            <?php endif; ?>

            <tr style="background:var(--surface-2);border-top:2px solid var(--border);">
                <td colspan="4" style="font-weight:700;">Closing balance</td>
                <td style="text-align:right;font-weight:800;"><?= e(money($closingBalance)) ?></td>
                <td colspan="4"></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="card animate-fade-up" style="margin-top:18px;font-size:.85rem;">
    <strong>Reconciliation</strong>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-top:12px;">
        <div>
            <div class="muted" style="font-size:.75rem;text-transform:uppercase;">Opening + Net effective</div>
            <div style="font-weight:700;margin-top:4px;">
                <?= e(money($openingBalance)) ?>
                + <?= e(money($totals['deposits'])) ?>
                − <?= e(money($totals['withdrawals'])) ?>
                = <?= e(money($closingBalance)) ?>
            </div>
        </div>
        <div>
            <div class="muted" style="font-size:.75rem;text-transform:uppercase;">Matches current account balance</div>
            <div style="font-weight:700;margin-top:4px;color:<?= abs($closingBalance - (float)$account['balance']) < 0.01 ? 'var(--accent)' : 'var(--danger)' ?>;">
                <?= abs($closingBalance - (float)$account['balance']) < 0.01 ? '✓ Yes' : '✗ No — check ledger' ?>
            </div>
        </div>
    </div>
    <div class="muted" style="font-size:.8rem;margin-top:12px;">
        Reversed and reversal rows are shown muted with strikethrough. They are excluded from the
        opening/closing calculation because their net effect is zero.
    </div>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>