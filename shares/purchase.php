<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/shares.php';

$faceValue = (float)(setting('share_face_value') ?? '10000');
$errors = [];
$old = [
    'member_id'     => (int)($_GET['member_id'] ?? 0),
    'qty'           => '1',
    'purchase_date' => date('Y-m-d'),
    'method'        => 'savings',
    'notes'         => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old['member_id']     = (int)($_POST['member_id'] ?? 0);
    $old['qty']           = trim((string)($_POST['qty'] ?? ''));
    $old['purchase_date'] = trim((string)($_POST['purchase_date'] ?? date('Y-m-d')));
    $old['method']        = $_POST['method'] ?? 'savings';
    $old['notes']         = trim((string)($_POST['notes'] ?? ''));

    if (!$old['member_id']) $errors[] = 'Select a member.';
    if (!is_numeric($old['qty']) || (int)$old['qty'] < 1) $errors[] = 'Quantity must be at least 1.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['purchase_date'])) $errors[] = 'Invalid date.';
    if (!in_array($old['method'], ['savings','cash','mobile_money','bank','other'], true)) $old['method'] = 'savings';

    if (!$errors) {
        try {
            $result = record_share_purchase(
                $pdo,
                $old['member_id'],
                (int)$old['qty'],
                $old['purchase_date'],
                $old['method'],
                $old['notes'] !== '' ? $old['notes'] : null,
                (int)$user['id']
            );

            audit_log($pdo, (int)$user['id'], 'share.purchase', 'member', $old['member_id'],
                "Bought {$result['qty']} shares for " . money($result['total']) . " (receipt {$result['receipt_no']})");

            flash('success',
                "Purchase recorded. {$result['qty']} shares · " .
                money($result['total']) . " · Receipt {$result['receipt_no']}");

            redirect('/scms/shares/member.php?id=' . $old['member_id']);
        } catch (Throwable $ex) {
            error_log('[shares/purchase] ' . $ex->getMessage());
            $errors[] = $ex->getMessage();
        }
    }
}

// Members with their savings balance + existing shares
$members = $pdo->query("
    SELECT m.id, m.member_no, m.first_name, m.last_name,
           COALESCE(sa.balance, 0) AS savings,
           COALESCE((SELECT SUM(qty) FROM share_purchases sp
                     WHERE sp.member_id = m.id AND sp.is_reversed = 0
                       AND (sp.reversal_of_id IS NULL OR sp.reversal_of_id = 0)), 0) AS current_shares
    FROM members m
    LEFT JOIN savings_accounts sa ON sa.member_id = m.id
    WHERE m.status = 'active'
    ORDER BY m.first_name, m.last_name
")->fetchAll();

$pageTitle = 'Purchase Shares';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Purchase shares</h1>
        <p><?= e(setting('share_label', 'Ordinary Shares')) ?> at <?= e(money($faceValue)) ?> per share</p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Shares</a>
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

        <label class="field">
            <span>Member *</span>
            <select name="member_id" id="memberSelect" required>
                <option value="">— Select member —</option>
                <?php foreach ($members as $m): ?>
                    <option value="<?= (int)$m['id'] ?>"
                            data-savings="<?= e((string)$m['savings']) ?>"
                            data-shares="<?= (int)$m['current_shares'] ?>"
                            <?= $old['member_id'] === (int)$m['id'] ? 'selected' : '' ?>>
                        <?= e($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['member_no'] . ') — ' .
                              number_format((int)$m['current_shares']) . ' shares · savings ' . money((float)$m['savings'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <label class="field">
                <span>Quantity *</span>
                <input type="number" name="qty" id="qtyInput" min="1" step="1" value="<?= e($old['qty']) ?>" required>
            </label>
            <label class="field">
                <span>Purchase date *</span>
                <input type="date" name="purchase_date" value="<?= e($old['purchase_date']) ?>" required>
            </label>
        </div>

        <div class="field">
            <span>Total cost</span>
            <div id="totalCost" style="font-size:1.4rem;font-weight:800;padding:11px 12px;background:var(--surface-2);border-radius:8px;border:1px solid var(--border-2);">
                <?= e(money($faceValue)) ?>
            </div>
            <small class="muted" style="font-size:.78rem;">Unit price: <?= e(money($faceValue)) ?></small>
        </div>

        <div class="field">
            <span>Payment method</span>
            <select name="method" id="methodSelect">
                <option value="savings"      <?= $old['method'] === 'savings' ? 'selected' : '' ?>>From savings account</option>
                <option value="cash"         <?= $old['method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                <option value="mobile_money" <?= $old['method'] === 'mobile_money' ? 'selected' : '' ?>>Mobile money</option>
                <option value="bank"         <?= $old['method'] === 'bank' ? 'selected' : '' ?>>Bank transfer</option>
                <option value="other"        <?= $old['method'] === 'other' ? 'selected' : '' ?>>Other</option>
            </select>
            <small class="muted" style="font-size:.78rem;">
                Choosing "From savings" debits the member's savings account automatically.
            </small>
        </div>

        <label class="field">
            <span>Notes</span>
            <textarea name="notes" rows="2"><?= e($old['notes']) ?></textarea>
        </label>

        <div style="display:flex;gap:12px;margin-top:20px;justify-content:flex-end;">
            <a href="index.php" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Record purchase</button>
        </div>
    </form>

    <div class="card animate-fade-up delay-1" style="align-self:flex-start;">
        <h3 class="card-title" style="margin-bottom:14px;">Member snapshot</h3>
        <div id="snapshot">
            <div class="center muted" style="padding:20px;">Select a member to see their details.</div>
        </div>
    </div>
</div>

<style>
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); margin-bottom:16px; }
.field input, .field select, .field textarea {
    padding:11px 12px; border:1.5px solid var(--border); border-radius:8px;
    background:var(--surface); color:var(--text); font:inherit; font-size:.95rem;
    width:100%;
}
.field input:focus, .field select:focus, .field textarea:focus {
    outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,.12);
}
@media (max-width:900px) {
    div[style*="grid-template-columns:2fr 1fr"] { grid-template-columns:1fr !important; }
    div[style*="grid-template-columns:1fr 1fr"] { grid-template-columns:1fr !important; }
}
</style>

<script>
const faceValue = <?= json_encode($faceValue) ?>;
const memberSelect = document.getElementById('memberSelect');
const qtyInput = document.getElementById('qtyInput');
const methodSelect = document.getElementById('methodSelect');
const totalCost = document.getElementById('totalCost');
const snapshot = document.getElementById('snapshot');

function fmt(n) {
    return new Intl.NumberFormat().format(Math.round(n * 100) / 100);
}

function updateTotal() {
    const qty = parseInt(qtyInput.value || '0', 10);
    totalCost.textContent = fmt(qty * faceValue);
}

function updateSnapshot() {
    const opt = memberSelect.options[memberSelect.selectedIndex];
    if (!opt || !opt.value) {
        snapshot.innerHTML = '<div class="center muted" style="padding:20px;">Select a member to see their details.</div>';
        return;
    }
    const savings = parseFloat(opt.dataset.savings || '0');
    const shares  = parseInt(opt.dataset.shares || '0', 10);
    snapshot.innerHTML = `
        <div style="display:flex;flex-direction:column;gap:14px;">
            <div>
                <div class="muted" style="font-size:.72rem;text-transform:uppercase;">Current shares</div>
                <div style="font-size:1.4rem;font-weight:800;">${shares}</div>
                <div class="muted" style="font-size:.8rem;">Worth ${fmt(shares * faceValue)}</div>
            </div>
            <div>
                <div class="muted" style="font-size:.72rem;text-transform:uppercase;">Savings balance</div>
                <div style="font-size:1.4rem;font-weight:800;">${fmt(savings)}</div>
            </div>
            <div id="warn" style="display:none;" class="alert alert-error" style="margin:0;"></div>
        </div>`;
    checkAffordability();
}

function checkAffordability() {
    const opt = memberSelect.options[memberSelect.selectedIndex];
    const warn = document.getElementById('warn');
    if (!warn || !opt || !opt.value) return;
    const savings = parseFloat(opt.dataset.savings || '0');
    const qty = parseInt(qtyInput.value || '0', 10);
    const cost = qty * faceValue;
    if (methodSelect.value === 'savings' && cost > savings) {
        warn.style.display = 'block';
        warn.textContent = 'Insufficient savings. Need ' + fmt(cost) + ', available ' + fmt(savings) + '.';
    } else {
        warn.style.display = 'none';
    }
}

memberSelect.addEventListener('change', updateSnapshot);
qtyInput.addEventListener('input', () => { updateTotal(); checkAffordability(); });
methodSelect.addEventListener('change', checkAffordability);

updateTotal();
updateSnapshot();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>