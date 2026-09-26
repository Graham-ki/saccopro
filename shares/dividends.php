<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/shares.php';

$errors = [];

// Handle declaration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $year            = (int)($_POST['year'] ?? 0);
    $overrideProfit  = trim((string)($_POST['override_profit'] ?? ''));
    $distributablePct= (float)($_POST['distributable_pct'] ?? 100);
    $minMonths       = (int)($_POST['min_months'] ?? 6);
    $basisNote       = trim((string)($_POST['basis_note'] ?? ''));

    if ($year < 2000 || $year > (int)date('Y') + 1) $errors[] = 'Invalid year.';
    if ($distributablePct < 0 || $distributablePct > 100) $errors[] = 'Distribution % must be 0–100.';
    if ($minMonths < 0) $errors[] = 'Minimum months cannot be negative.';

    $overrideValue = null;
    if ($overrideProfit !== '') {
        if (!is_numeric($overrideProfit) || (float)$overrideProfit < 0) {
            $errors[] = 'Override profit must be a non-negative number.';
        } else {
            $overrideValue = (float)$overrideProfit;
        }
    }

    if (!$errors) {
        try {
            $computed = computed_year_interest($pdo, $year);
            $dividendId = declare_dividend(
                $pdo, $year, $computed, $overrideValue, $distributablePct, $minMonths,
                $basisNote !== '' ? $basisNote : null, (int)$user['id']
            );

            audit_log($pdo, (int)$user['id'], 'dividend.declare', 'dividend', $dividendId,
                "Declared dividend for {$year}");

            flash('success', "Dividend for {$year} declared. Review and pay out from the detail page.");
            redirect('/scms/shares/dividend-view.php?id=' . $dividendId);
        } catch (Throwable $ex) {
            error_log('[shares/dividends] ' . $ex->getMessage());
            $errors[] = $ex->getMessage();
        }
    }
}

// Existing dividends
$dividends = $pdo->query("
    SELECT d.*,
           (SELECT COUNT(*) FROM dividend_payouts WHERE dividend_id = d.id) AS payout_count,
           (SELECT COUNT(*) FROM dividend_payouts WHERE dividend_id = d.id AND paid_at IS NOT NULL) AS paid_count
    FROM dividends d
    ORDER BY d.year DESC
")->fetchAll();

// Preview for the "current year" (for the info card)
$thisYear = (int)date('Y');
$computedThisYear = computed_year_interest($pdo, $thisYear);

$pageTitle = 'Dividends';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.dash-grid { display:grid; grid-template-columns:1.2fr 1fr; gap:18px; }
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); margin-bottom:14px; }
.field input, .field select, .field textarea {
    padding:11px 12px; border:1.5px solid var(--border); border-radius:8px;
    background:var(--surface); color:var(--text); font:inherit; font-size:.95rem;
}
.field input:focus, .field select:focus, .field textarea:focus {
    outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,.12);
}
.field input:disabled { opacity:.7; cursor:not-allowed; }
@media (max-width:900px) {
    .dash-grid { grid-template-columns:1fr; }
    div[style*="grid-template-columns:1fr 1fr"] { grid-template-columns:1fr !important; }
}
</style>
<div class="page-head">
    <div>
        <h1>Dividends</h1>
        <p>Declare and manage annual dividend distributions.</p>
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
<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<div class="dash-grid">

    <!-- Declare new dividend -->
    <div class="card animate-fade-up">
        <h3 class="card-title" style="margin-bottom:12px;">Declare a dividend</h3>
        <p class="muted" style="font-size:.85rem;margin-bottom:16px;">
            Profit pool defaults to interest earned during the year.
            You can override it. Distribution is based on shares held at 31 Dec, weighted by
            active membership ≥ minimum months.
        </p>

        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <label class="field"><span>Year *</span>
                    <input type="number" name="year" min="2000" max="<?= date('Y') + 1 ?>"
                           value="<?= $thisYear ?>" required>
                </label>
                <label class="field"><span>Computed interest</span>
                    <input type="text" value="<?= e(money($computedThisYear)) ?>" disabled>
                </label>
            </div>

            <div class="field">
                <span>Override profit pool (leave blank to use computed)</span>
                <input type="number" name="override_profit" step="0.01" min="0" placeholder="e.g. 5000000">
                <small class="muted" style="font-size:.75rem;">
                    If left blank, uses interest earned (<?= e(money($computedThisYear)) ?> for <?= $thisYear ?>).
                </small>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <label class="field"><span>Distribution % *</span>
                    <input type="number" name="distributable_pct" min="0" max="100" step="0.01"
                           value="<?= e(setting('dividend_default_pct', '100')) ?>" required>
                </label>
                <label class="field"><span>Min months membership *</span>
                    <input type="number" name="min_months" min="0"
                           value="<?= e(setting('dividend_min_months', '6')) ?>" required>
                </label>
            </div>

            <label class="field"><span>Basis note (optional)</span>
                <textarea name="basis_note" rows="2" placeholder="e.g. Board resolution 2024-12-15"></textarea>
            </label>

            <button class="btn btn-primary btn-block" style="margin-top:14px;"
                    onclick="return confirm('Declare a dividend? You will review payouts before they are paid.');">
                Preview &amp; declare
            </button>
        </form>
    </div>

    <!-- Existing dividends -->
    <div class="card animate-fade-up delay-1" style="align-self:flex-start;">
        <h3 class="card-title" style="margin-bottom:12px;">Declared dividends</h3>
        <?php if ($dividends): ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($dividends as $d): ?>
                    <a href="dividend-view.php?id=<?= (int)$d['id'] ?>"
                       style="display:block;text-decoration:none;color:inherit;padding:12px;border:1px solid var(--border-2);border-radius:10px;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                            <div>
                                <div style="font-weight:700;"><?= (int)$d['year'] ?></div>
                                <div class="muted" style="font-size:.78rem;">
                                    <?= (int)$d['paid_count'] ?>/<?= (int)$d['payout_count'] ?> paid ·
                                    <?= (int)$d['total_eligible_shares'] ?> eligible shares
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-weight:800;"><?= e(money((float)$d['distributable_amount'])) ?></div>
                                <span class="badge badge-<?= $d['status'] === 'paid' ? 'approved' : ($d['status'] === 'cancelled' ? 'rejected' : 'pending') ?>">
                                    <?= e($d['status']) ?>
                                </span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="center muted" style="padding:32px;">No dividends declared yet.</div>
        <?php endif; ?>
    </div>
</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>