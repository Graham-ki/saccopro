<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();

$txnId = (int)($_GET['txn_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT st.*, sa.account_no, m.first_name, m.last_name, m.member_no, m.phone,
           u.full_name AS recorded_by_name
    FROM savings_transactions st
    JOIN savings_accounts sa ON sa.id = st.account_id
    JOIN members m ON m.id = sa.member_id
    LEFT JOIN users u ON u.id = st.recorded_by
    WHERE st.id = ? LIMIT 1
");
$stmt->execute([$txnId]);
$txn = $stmt->fetch();

if (!$txn) {
    flash('error', 'Transaction not found.');
    redirect('/scms/savings/history.php');
}

$orgName = setting('org_name', 'My SACCO');
$footer  = setting('receipt_footer', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt <?= e($txn['reference']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Courier New', monospace;
            background: #f0f0f0;
            padding: 24px;
            display: grid;
            place-items: center;
            min-height: 100vh;
        }
        .receipt {
            width: 340px;
            background: #fff;
            padding: 24px 20px;
            border-radius: 6px;
            box-shadow: 0 4px 20px rgba(0,0,0,.12);
        }
        .receipt h1 { font-size: 1.1rem; text-align: center; margin-bottom: 4px; }
        .receipt .sub { text-align: center; font-size: .8rem; color: #666; margin-bottom: 18px; }
        .divider { border-top: 1px dashed #999; margin: 12px 0; }
        .row { display: flex; justify-content: space-between; gap: 12px; font-size: .82rem; padding: 3px 0; }
        .row .label { color: #666; }
        .row .value { font-weight: bold; text-align: right; }
        .amount {
            font-size: 1.5rem;
            text-align: center;
            font-weight: bold;
            padding: 14px 0;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            margin: 14px 0;
        }
        .footer { text-align: center; font-size: .72rem; color: #666; margin-top: 18px; }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border: 1px solid #000;
            border-radius: 4px;
            font-size: .7rem;
            text-transform: uppercase;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .receipt { box-shadow: none; border-radius: 0; width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
<div class="receipt">
    <h1><?= e($orgName) ?></h1>
    <div class="sub">Transaction Receipt</div>

    <div class="divider"></div>

    <div class="row"><span class="label">Ref</span><span class="value"><?= e($txn['reference']) ?></span></div>
    <div class="row"><span class="label">Date</span><span class="value"><?= e(date('M j, Y', strtotime($txn['transaction_date']))) ?></span></div>

    <div class="divider"></div>

    <div class="row"><span class="label">Member</span><span class="value"><?= e($txn['first_name'] . ' ' . $txn['last_name']) ?></span></div>
    <div class="row"><span class="label">Member No.</span><span class="value"><?= e($txn['member_no']) ?></span></div>
    <div class="row"><span class="label">Account</span><span class="value"><?= e($txn['account_no']) ?></span></div>
    <div class="row"><span class="label">Phone</span><span class="value"><?= e($txn['phone'] ?: '—') ?></span></div>

    <div class="amount">
        <?= $txn['type'] === 'deposit' ? '+' : '−' ?><?= e(money((float)$txn['amount'])) ?>
    </div>

    <div class="row"><span class="label">Type</span><span class="value"><span class="badge"><?= e(ucfirst($txn['type'])) ?></span></span></div>
    <div class="row"><span class="label">Balance after</span><span class="value"><?= e(money((float)$txn['balance_after'])) ?></span></div>

    <?php if (!empty($txn['notes'])): ?>
        <div class="divider"></div>
        <div class="row"><span class="label">Notes</span><span class="value" style="font-weight:normal;"><?= e($txn['notes']) ?></span></div>
    <?php endif; ?>

    <div class="divider"></div>

    <div class="row"><span class="label">Recorded by</span><span class="value"><?= e($txn['recorded_by_name'] ?: 'System') ?></span></div>

    <?php if ($footer): ?>
        <div class="footer"><?= e($footer) ?></div>
    <?php endif; ?>

    <div class="footer">Printed <?= e(date('M j, Y g:ia')) ?></div>
</div>

<div class="no-print" style="margin-top:20px;text-align:center;">
    <button onclick="window.print()" style="padding:10px 20px;border:none;border-radius:6px;background:#4f46e5;color:#fff;font:inherit;cursor:pointer;margin-right:8px;">🖨️ Print</button>
    <a href="/scms/savings/statement.php?account_id=<?= (int)$txn['account_id'] ?>" style="padding:10px 20px;border:1px solid #ccc;border-radius:6px;text-decoration:none;color:#333;font:inherit;">← Back to statement</a>
</div>

<script>
// Auto-open print dialog when ?print=1 is present
if (new URLSearchParams(location.search).get('print') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 200));
}
</script>
</body>
</html>