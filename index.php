<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/settings.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- If logged in, send them straight to the dashboard ----
if (!empty($_SESSION['user_id'])) {
    header('Location: /scms/dashboard/index.php');
    exit;
}

// ---- Load settings (works whether logged in or not) ----
$orgName = 'SACCOPRO';
try {
    $SETTINGS = load_settings($pdo);
    $orgName  = $SETTINGS['org_name'] ?? 'SACCOPRO';
} catch (Throwable $e) {
    // Settings table may not exist yet on first install — ignore
}

// ---- Optional: apply timezone if set ----
if (!empty($SETTINGS['timezone'])) {
    @date_default_timezone_set($SETTINGS['timezone']);
}

// ---- Public stats for the hero (safe if tables are empty) ----
$publicStats = [
    'members'      => 0,
    'savings'      => 0.0,
    'loans'        => 0,
    'transactions' => 0,
];

try {
    $row = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM members)                           AS members,
            (SELECT COALESCE(SUM(balance), 0) FROM savings_accounts) AS savings,
            (SELECT COUNT(*) FROM loans)                             AS loans,
            (SELECT COUNT(*) FROM savings_transactions)              AS transactions
    ")->fetch();
    if ($row) {
        $publicStats['members']      = (int)$row['members'];
        $publicStats['savings']      = (float)$row['savings'];
        $publicStats['loans']        = (int)$row['loans'];
        $publicStats['transactions'] = (int)$row['transactions'];
    }
} catch (Throwable $e) {
    // Tables may not exist yet — fall back to zeros
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($orgName) ?> — Savings &amp; Credit Management System</title>
    <meta name="description" content="Record, track, and manage member savings, loans, shares and dividends with a modern, reliable platform.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/scms/assets/css/style.css">
</head>
<body class="landing">

<!-- Navigation -->
<nav class="landing-nav" id="nav">
    <a href="#top" class="landing-brand">
        <span class="brand-mark"><?= e(strtoupper(substr($orgName, 0, 2))) ?></span>
        <span><?= e($orgName) ?></span>
    </a>
    <div class="landing-links">
        <a href="#features">Features</a>
        <a href="#how">How it works</a>
        <a href="#why">Why us</a>
    </div>
    <div class="landing-cta">
        <a href="/scms/auth/login.php" class="btn btn-ghost">Log in</a>
        <a href="/scms/auth/register.php" class="btn btn-primary">Get started</a>
    </div>
</nav>

<!-- Hero -->
<section class="hero" id="top">
    <div class="animate-fade-up">
        <span class="hero-badge">
            <span class="dot"></span>
            Complete record-keeping for SACCOs &amp; community groups
        </span>
        <h1>Manage <span class="grad">savings, credit &amp; shares</span> with clarity and confidence.</h1>
        <p class="lead">
            <?= e($orgName) ?> helps cooperatives, self-help groups, and small lenders
            record member savings, issue loans, track repayments, and distribute dividends —
            all in one clean, reliable platform.
        </p>
        <div class="hero-actions">
            <a href="/scms/auth/register.php" class="btn btn-primary btn-lg">
                Create free account
            </a>
            <a href="#how" class="btn btn-outline btn-lg">
                See how it works
            </a>
        </div>
        <div class="hero-stats">
            <div>
                <span class="hero-stat-num" data-count="<?= (int)$publicStats['members'] ?>">0</span>
                <span class="hero-stat-label">Members registered</span>
            </div>
            <div>
                <span class="hero-stat-num" data-count="<?= (int)$publicStats['loans'] ?>">0</span>
                <span class="hero-stat-label">Loans issued</span>
            </div>
            <div>
                <span class="hero-stat-num" data-count="<?= (int)$publicStats['transactions'] ?>">0</span>
                <span class="hero-stat-label">Transactions recorded</span>
            </div>
        </div>
    </div>

    <div class="hero-visual animate-fade-in delay-3">
        <div class="hero-card">
            <div class="hero-card-head">
                <div>
                    <div class="hero-card-title">Financial overview</div>
                    <div class="hero-card-sub">Live from your records</div>
                </div>
                <span class="badge badge-approved">Real-time</span>
            </div>
            <div class="hero-chart">
                <div class="bar" style="height: 40%; animation-delay: .1s;"></div>
                <div class="bar" style="height: 65%; animation-delay: .2s;"></div>
                <div class="bar" style="height: 50%; animation-delay: .3s;"></div>
                <div class="bar" style="height: 80%; animation-delay: .4s;"></div>
                <div class="bar" style="height: 60%; animation-delay: .5s;"></div>
                <div class="bar" style="height: 92%; animation-delay: .6s;"></div>
                <div class="bar" style="height: 72%; animation-delay: .7s;"></div>
                <div class="bar" style="height: 88%; animation-delay: .8s;"></div>
            </div>
            <div class="hero-mini-cards">
                <div class="hero-mini">
                    <div class="hero-mini-label">Total savings</div>
                    <div class="hero-mini-value"><?= e(money($publicStats['savings'])) ?></div>
                </div>
                <div class="hero-mini">
                    <div class="hero-mini-label">Active members</div>
                    <div class="hero-mini-value"><?= number_format((int)$publicStats['members']) ?></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Features -->
<section class="section" id="features">
    <div class="section-head">
        <div class="section-eyebrow">Everything included</div>
        <h2>All the tools your group needs, in one place</h2>
        <p>From membership to loans to dividends — the entire finance workflow of a SACCO is covered.</p>
    </div>

    <!-- Category 1: Membership & Access -->
    <h3 style="text-align:center;margin:48px 0 24px;font-size:1.05rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);">
        Membership &amp; Access
    </h3>
    <div class="features">
        <div class="feature animate-fade-up">
            <div class="feature-icon" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);color:#4f46e5;">👥</div>
            <h3>Member records</h3>
            <p>Store member details, contacts, IDs, join dates, and status. Each member gets a savings account automatically.</p>
        </div>
        <div class="feature animate-fade-up delay-1">
            <div class="feature-icon" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);color:#4f46e5;">🔐</div>
            <h3>Role-based access</h3>
            <p>Admins approve accounts and manage settings. Clerks record transactions. Everyone sees only what they should.</p>
        </div>
        <div class="feature animate-fade-up delay-2">
            <div class="feature-icon" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);color:#4f46e5;">📥</div>
            <h3>Bulk member import</h3>
            <p>Onboard an existing group in minutes. Import members from a CSV file with a preview step before committing.</p>
        </div>
        <div class="feature animate-fade-up delay-3">
            <div class="feature-icon" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);color:#4f46e5;">👤</div>
            <h3>Profile &amp; settings</h3>
            <p>Every user manages their own profile. Admins configure organization name, currency, and loan defaults.</p>
        </div>
    </div>

    <!-- Category 2: Savings -->
    <h3 style="text-align:center;margin:64px 0 24px;font-size:1.05rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);">
        Savings
    </h3>
    <div class="features">
        <div class="feature animate-fade-up">
            <div class="feature-icon" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);color:#16a34a;">💰</div>
            <h3>Deposits &amp; withdrawals</h3>
            <p>Record transactions in seconds. Balances update instantly with atomic, race-safe postings.</p>
        </div>
        <div class="feature animate-fade-up delay-1">
            <div class="feature-icon" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);color:#16a34a;">📑</div>
            <h3>Member statements</h3>
            <p>Generate printable statements for any period. Opening balance, transactions, and closing balance — verified against the ledger.</p>
        </div>
        <div class="feature animate-fade-up delay-2">
            <div class="feature-icon" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);color:#16a34a;">🧾</div>
            <h3>Printable receipts</h3>
            <p>Every transaction produces a clean, professional receipt ready to print or save as PDF.</p>
        </div>
        <div class="feature animate-fade-up delay-3">
            <div class="feature-icon" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);color:#16a34a;">↩️</div>
            <h3>Safe reversals</h3>
            <p>Mistakes happen. Reverse a transaction with a reason — the system posts a counter-entry, keeps the audit trail, and recalculates balances.</p>
        </div>
    </div>

    <!-- Category 3: Credit & Loans -->
    <h3 style="text-align:center;margin:64px 0 24px;font-size:1.05rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);">
        Credit &amp; Loans
    </h3>
    <div class="features">
        <div class="feature animate-fade-up">
            <div class="feature-icon" style="background:linear-gradient(135deg,#fef3c7,#fde68a);color:#d97706;">📄</div>
            <h3>Loan issuance &amp; schedules</h3>
            <p>Issue loans with flat or declining-balance interest. The amortization schedule is generated automatically and previewed live.</p>
        </div>
        <div class="feature animate-fade-up delay-1">
            <div class="feature-icon" style="background:linear-gradient(135deg,#fef3c7,#fde68a);color:#d97706;">💳</div>
            <h3>Repayments</h3>
            <p>Record payments that allocate to the oldest unpaid installments first. Interest and principal tracked separately.</p>
        </div>
        <div class="feature animate-fade-up delay-2">
            <div class="feature-icon" style="background:linear-gradient(135deg,#fef3c7,#fde68a);color:#d97706;">📈</div>
            <h3>Loan top-ups</h3>
            <p>Extend an active loan with additional principal. The schedule is rebuilt from the remaining balance — history stays intact.</p>
        </div>
        <div class="feature animate-fade-up delay-3">
            <div class="feature-icon" style="background:linear-gradient(135deg,#fef3c7,#fde68a);color:#d97706;">👥</div>
            <h3>Guarantors</h3>
            <p>Attach members as guarantors with pledge amounts. Track each member's total exposure and enforce savings-based caps.</p>
        </div>
        <div class="feature animate-fade-up delay-4">
            <div class="feature-icon" style="background:linear-gradient(135deg,#fef3c7,#fde68a);color:#d97706;">⏰</div>
            <h3>Overdue tracking</h3>
            <p>Automatic aging buckets (current, 1–30, 31–60, 61–90, 90+ days). See who's late at a glance.</p>
        </div>
    </div>

    <!-- Category 4: Shares & Dividends -->
    <h3 style="text-align:center;margin:64px 0 24px;font-size:1.05rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);">
        Shares &amp; Dividends
    </h3>
    <div class="features">
        <div class="feature animate-fade-up">
            <div class="feature-icon" style="background:linear-gradient(135deg,#f3e8ff,#e9d5ff);color:#9333ea;">🏦</div>
            <h3>Share capital</h3>
            <p>Members buy shares at a fixed face value. Purchases debit their savings automatically and credit the share pool.</p>
        </div>
        <div class="feature animate-fade-up delay-1">
            <div class="feature-icon" style="background:linear-gradient(135deg,#f3e8ff,#e9d5ff);color:#9333ea;">📊</div>
            <h3>Ownership tracking</h3>
            <p>See each member's share count, value, and percentage of total capital. Export the full share register any time.</p>
        </div>
        <div class="feature animate-fade-up delay-2">
            <div class="feature-icon" style="background:linear-gradient(135deg,#f3e8ff,#e9d5ff);color:#9333ea;">💵</div>
            <h3>Annual dividends</h3>
            <p>Declare a dividend from a computed or manual profit pool. Eligibility is based on membership duration and shares held.</p>
        </div>
        <div class="feature animate-fade-up delay-3">
            <div class="feature-icon" style="background:linear-gradient(135deg,#f3e8ff,#e9d5ff);color:#9333ea;">🎯</div>
            <h3>One-click payout</h3>
            <p>Approved dividends post straight to members' savings accounts with unique references. Fully auditable and reversible.</p>
        </div>
    </div>

    <!-- Category 5: Reports & Insights -->
    <h3 style="text-align:center;margin:64px 0 24px;font-size:1.05rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);">
        Reports &amp; Insights
    </h3>
    <div class="features">
        <div class="feature animate-fade-up">
            <div class="feature-icon" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#2563eb;">📊</div>
            <h3>Reports dashboard</h3>
            <p>Overview, Members, Savings, Loans, and Income reports — all filterable by date range with KPIs and totals.</p>
        </div>
        <div class="feature animate-fade-up delay-1">
            <div class="feature-icon" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#2563eb;">📉</div>
            <h3>Charts &amp; trends</h3>
            <p>See savings flow trends, loan portfolios, and monthly activity with clean, embeddable visualisations.</p>
        </div>
        <div class="feature animate-fade-up delay-2">
            <div class="feature-icon" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#2563eb;">⬇️</div>
            <h3>CSV exports</h3>
            <p>Download any report as a CSV for Excel, accounting, or board reporting.</p>
        </div>
        <div class="feature animate-fade-up delay-3">
            <div class="feature-icon" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#2563eb;">🔔</div>
            <h3>Real notifications</h3>
            <p>Pending approvals, upcoming dues, and overdue loans surface automatically on the dashboard.</p>
        </div>
    </div>

    <!-- Category 6: Administration & Safety -->
    <h3 style="text-align:center;margin:64px 0 24px;font-size:1.05rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);">
        Administration &amp; Safety
    </h3>
    <div class="features">
        <div class="feature animate-fade-up">
            <div class="feature-icon" style="background:linear-gradient(135deg,#fee2e2,#fecaca);color:#dc2626;">📋</div>
            <h3>Audit log</h3>
            <p>Every create, update, reversal, and approval is logged with user, timestamp, and IP. Full accountability.</p>
        </div>
        <div class="feature animate-fade-up delay-1">
            <div class="feature-icon" style="background:linear-gradient(135deg,#fee2e2,#fecaca);color:#dc2626;">💾</div>
            <h3>Backup &amp; restore</h3>
            <p>Download a complete SQL dump at any time. Restore in seconds from the same admin panel.</p>
        </div>
        <div class="feature animate-fade-up delay-2">
            <div class="feature-icon" style="background:linear-gradient(135deg,#fee2e2,#fecaca);color:#dc2626;">⚙️</div>
            <h3>Configurable</h3>
            <p>Organization name, currency, interest defaults, share value, and dividend rules — all editable from settings.</p>
        </div>
        <div class="feature animate-fade-up delay-3">
            <div class="feature-icon" style="background:linear-gradient(135deg,#fee2e2,#fecaca);color:#dc2626;">📱</div>
            <h3>Works everywhere</h3>
            <p>Fully responsive. Runs on a desktop in the office, a tablet at a meeting, or a phone in the field.</p>
        </div>
    </div>
</section>

<!-- How it works -->
<section class="section" id="how">
    <div class="section-head">
        <div class="section-eyebrow">How it works</div>
        <h2>Up and running in four steps</h2>
        <p>From registration to your first recorded transaction — the whole flow is quick and clear.</p>
    </div>
    <div class="steps">
        <div class="step animate-fade-up">
            <h3>Register</h3>
            <p>Create your account with a username and email. Takes under a minute.</p>
        </div>
        <div class="step animate-fade-up delay-1">
            <h3>Get approved</h3>
            <p>An admin reviews and approves your account before you can sign in.</p>
        </div>
        <div class="step animate-fade-up delay-2">
            <h3>Add your members</h3>
            <p>Register members individually or import a CSV. Each gets a savings account automatically.</p>
        </div>
        <div class="step animate-fade-up delay-3">
            <h3>Start recording</h3>
            <p>Log savings, issue loans, sell shares, declare dividends. Reports and statements update in real time.</p>
        </div>
    </div>
</section>

<!-- CTA band -->
<section class="cta-band animate-fade-up" id="why">
    <h2>Ready to bring order to your records?</h2>
    <p>Join the groups already using <?= e($orgName) ?> to run their savings, credit, and share operations with confidence.</p>
    <a href="/scms/auth/register.php" class="btn-white">Create your account</a>
    <a href="/scms/auth/login.php" class="btn-ghost">I already have one</a>
</section>

<footer class="landing-footer">
    <p>&copy; <?= date('Y') ?> <?= e($orgName) ?> — Savings &amp; Credit Management System. Built for community finance.</p>
</footer>

<script>
// Scroll-triggered nav shadow
const nav = document.getElementById('nav');
window.addEventListener('scroll', () => {
    nav.classList.toggle('scrolled', window.scrollY > 20);
});

// Animated counters
document.querySelectorAll('[data-count]').forEach(el => {
    const target = +el.dataset.count || 0;
    const suffix = el.dataset.suffix || '';
    const duration = 1600;
    const start = performance.now();
    const tick = now => {
        const p = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = Math.round(target * eased).toLocaleString() + suffix;
        if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
});

// Reveal on scroll
const io = new IntersectionObserver((entries) => {
    entries.forEach(e => {
        if (e.isIntersecting) {
            e.target.style.animationPlayState = 'running';
            io.unobserve(e.target);
        }
    });
}, { threshold: 0.1 });
document.querySelectorAll('.animate-fade-up, .animate-fade-in').forEach(el => io.observe(el));
</script>

</body>
</html>