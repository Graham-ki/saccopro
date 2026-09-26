<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_member();
$member = current_member();
require_once __DIR__ . '/../includes/requests.php';
require_once __DIR__ . '/../includes/shares.php';

$faceValue = (float)(setting('share_face_value') ?? '10000');
$balance   = (float)($member['balance'] ?? 0);
$sharesHeld = member_shares_held($pdo, (int)$member['id']);

$errors = [];
$old = ['qty' => '1', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $old['qty']   = trim((string)($_POST['qty'] ?? ''));
    $old['notes'] = trim((string)($_POST['notes'] ?? ''));

    $qty = (int)$old['qty'];
    if ($qty < 1) $errors[] = 'Quantity must be at least 1.';
    if (!$errors && $qty * $faceValue > $balance) {
        $errors[] = 'Total cost (' . money($qty * $faceValue) . ') exceeds your savings balance (' . money($balance) . ').';
    }

    if (!$errors) {
        try {
            $id = create_member_request($pdo, (int)$member['id'], 'share_purchase', [
                'qty'   => $qty,
                'notes' => $old['notes'] !== '' ? $old['notes'] : null,
            ]);
            audit_log($pdo, (int)$user['id'], 'portal.request_shares', 'member_request', $id,
                "Requested {$qty} shares");
            flash('success', 'Share purchase request submitted. Awaiting approval.');
            redirect('/scms/portal/requests.php');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'Buy Shares';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); margin-bottom:14px; }
.field input, .field textarea { padding:11px 12px; border:1.5px solid var(--border); border-radius:8px; background:var(--surface); color:var(--text); font:inherit; font-size:.95rem; }
.field input:focus, .field textarea:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,.12); }
</style>
<div class="page-head">
    <div>
        <h1>Buy shares</h1>
        <p><?= e(setting('share_label', 'Ordinary Shares')) ?> at <?= e(money($faceValue)) ?> per share</p>
    </div>
    <div class="page-head-actions">
        <a href="shares.php" class="btn btn-ghost">← My Shares</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <ul style="margin:0;padding-left:18px;">
            <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div style="max-width:600px;">
    <form method="post" class="card animate-fade-up" id="shareForm">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div style="padding:14px;border-radius:10px;background:var(--surface-2);border:1px solid var(--border-2);margin-bottom:16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
            <div>
                <div class="muted" style="font-size:.72rem;text-transform:uppercase;">Shares held</div>
                <div style="font-weight:700;"><?= number_format($sharesHeld) ?></div>
            </div>
            <div>
                <div class="muted" style="font-size:.72rem;text-transform:uppercase;">Savings balance</div>
                <div style="font-weight:700;"><?= e(money($balance)) ?></div>
            </div>
            <div>
                <div class="muted" style="font-size:.72rem;text-transform:uppercase;">Face value</div>
                <div style="font-weight:700;"><?= e(money($faceValue)) ?></div>
            </div>
        </div>

        <label class="field"><span>Quantity *</span>
            <input type="number" name="qty" id="qtyInput" min="1" step="1" value="<?= e($old['qty']) ?>" required autofocus>
        </label>

        <div class="field">
            <span>Total cost</span>
            <div id="totalCost" style="font-size:1.4rem;font-weight:800;padding:11px 12px;background:var(--surface-2);border-radius:8px;border:1px solid var(--border-2);">
                <?= e(money($faceValue)) ?>
            </div>
        </div>

        <label class="field"><span>Notes (optional)</span>
            <textarea name="notes" rows="2"><?= e($old['notes']) ?></textarea>
        </label>

        <div id="warn" style="display:none;" class="alert alert-error">Total cost exceeds your savings balance.</div>

        <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:14px;">
            <a href="index.php" class="btn btn-ghost">Cancel</a>
            <button class="btn btn-primary" id="submitBtn">Submit request</button>
        </div>
    </form>
</div>



<script>
const face = <?= json_encode($faceValue) ?>;
const balance = <?= json_encode($balance) ?>;
const qtyInput = document.getElementById('qtyInput');
const totalCost = document.getElementById('totalCost');
const warn = document.getElementById('warn');
const submitBtn = document.getElementById('submitBtn');

function fmt(n) { return new Intl.NumberFormat().format(Math.round(n)); }

function update() {
    const qty = parseInt(qtyInput.value || '0', 10);
    const total = qty * face;
    totalCost.textContent = fmt(total);
    if (total > balance) {
        warn.style.display = 'block';
        submitBtn.disabled = true;
    } else {
        warn.style.display = 'none';
        submitBtn.disabled = false;
    }
}
qtyInput.addEventListener('input', update);
update();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>