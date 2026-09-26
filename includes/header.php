<?php
require_once __DIR__ . '/auth.php';
$user = require_login();

require_once __DIR__ . '/notifications.php';
$notifData     = build_notifications($pdo, $user);
$notifications = $notifData['items'];
$unreadCount   = $notifData['unread'];

// Pending member requests count (for the admin sidebar badge)
$pendingRequestsCount = 0;
if (($user['role'] ?? '') === 'admin') {
    try {
        $pendingRequestsCount = (int)$pdo->query("
            SELECT COUNT(*) FROM member_requests WHERE status = 'pending'
        ")->fetchColumn();
    } catch (Throwable $e) {
        // table may not exist yet
    }
}

$orgName  = setting('org_name', 'SCMS');
$orgShort = setting('org_short_name', 'SCMS');

// ---- Active-page detection (path-based) ----
$currentPath = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');

$active = static function (string $needle) use ($currentPath): string {
    return str_contains($currentPath, $needle) ? 'active' : '';
};

$initial = strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1));

$isMember  = ($user['role'] ?? '') === 'member';
$brandHref = $isMember ? '/scms/portal/index.php' : '/scms/dashboard/index.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Dashboard') ?> · <?= e($orgShort) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/scms/assets/css/style.css">
</head>
<body>
<div class="app">

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <a href="<?= e($brandHref) ?>" class="landing-brand">
                <span class="brand-mark"><?= e(strtoupper(substr($orgShort, 0, 2))) ?></span>
                <span><?= e($orgShort) ?></span>
            </a>
        </div>

        <nav class="sidebar-nav">
<?php if ($isMember): ?>

            <!-- ============ MEMBER PORTAL MENU ============ -->
            <div class="nav-group-label">My Account</div>
            <a href="/scms/portal/index.php" class="nav-item <?= $active('/portal/index.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-9 9 9M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Overview
            </a>
            <a href="/scms/portal/savings.php" class="nav-item <?= $active('/portal/savings.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                My Savings
            </a>
            <a href="/scms/portal/loans.php" class="nav-item <?= $active('/portal/loans.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                My Loans
            </a>
            <a href="/scms/portal/shares.php" class="nav-item <?= $active('/portal/shares.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 14l4-4 3 3 5-6"/>
                </svg>
                My Shares
            </a>
            <a href="/scms/portal/profile.php" class="nav-item <?= $active('/portal/profile.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                My Profile
            </a>

            <div class="nav-group-label">Requests</div>
            <a href="/scms/portal/requests.php" class="nav-item <?= $active('/portal/requests.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                My Requests
            </a>
            <a href="/scms/portal/request-deposit.php" class="nav-item <?= $active('request-deposit.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m0 0l-4-4m4 4l4-4"/>
                </svg>
                Request Deposit
            </a>
            <a href="/scms/portal/request-withdrawal.php" class="nav-item <?= $active('request-withdrawal.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m0 0l4-4m-4 4l-4-4"/>
                </svg>
                Request Withdrawal
            </a>
            <a href="/scms/portal/request-loan.php" class="nav-item <?= $active('request-loan.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Request Loan
            </a>
            <a href="/scms/portal/request-shares.php" class="nav-item <?= $active('request-shares.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Buy Shares
            </a>

<?php else: ?>

            <!-- ============ STAFF MENU ============ -->
            <div class="nav-group-label">Main</div>
            <a href="/scms/dashboard/index.php" class="nav-item <?= $active('/dashboard/') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-9 9 9M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Dashboard
            </a>

            <div class="nav-group-label">Operations</div>
            <a href="/scms/members/index.php" class="nav-item <?= $active('/members/') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-5.13a4 4 0 11-8 0 4 4 0 018 0zm6 0a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                Members
            </a>
            <a href="/scms/savings/index.php" class="nav-item <?= $active('/savings/') && !$active('history.php') ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Savings
            </a>
            <a href="/scms/loans/index.php" class="nav-item <?= $active('/loans/') && !$active('repayments.php') ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Loans
            </a>
            <a href="/scms/loans/repayments.php" class="nav-item <?= $active('repayments.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8"/>
                </svg>
                Repayments
            </a>
            <a href="/scms/shares/index.php" class="nav-item <?= $active('/shares/') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 14l4-4 3 3 5-6"/>
                </svg>
                Shares
            </a>

            <div class="nav-group-label">Insights</div>
            <a href="/scms/reports/index.php" class="nav-item <?= $active('/reports/') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                Reports
            </a>
            <a href="/scms/savings/history.php" class="nav-item <?= $active('history.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Transactions
            </a>
            

            <?php if ($user['role'] === 'admin'): ?>
                <div class="nav-group-label">Admin</div>
                <a href="/scms/admin/requests.php" class="nav-item <?= $active('/admin/requests.php') ?>">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    Member Requests
                    <?php if ($pendingRequestsCount > 0): ?>
                        <span class="badge-count"><?= (int)$pendingRequestsCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="/scms/admin/users.php" class="nav-item <?= $active('/admin/users.php') ?>">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                    Users
                </a>
                <a href="/scms/admin/link-member.php" class="nav-item <?= $active('/admin/link-member.php') ?>">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                    Portal Access
                </a>
                <a href="/scms/admin/audit.php" class="nav-item <?= $active('/admin/audit.php') ?>">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                    Audit Log
                </a>
                <a href="/scms/admin/backup.php" class="nav-item <?= $active('/admin/backup.php') ?>">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                    </svg>
                    Backup
                </a>
                <a href="/scms/admin/import-members.php" class="nav-item <?= $active('/admin/import-members.php') ?>">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0 0l-4-4m4 4l4-4"/>
                    </svg>
                    Import Members
                </a>
                <a href="/scms/settings.php" class="nav-item <?= $active('/settings.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
               System Settings
            </a>
            <?php endif; ?>

<?php endif; ?>
        </nav>

        <div class="sidebar-foot">
            <?= e($orgShort) ?> v1.0
        </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- Main -->
    <div class="main">

        <!-- Topbar -->
        <header class="topbar">
            <button class="icon-btn menu-toggle" id="menuToggle" aria-label="Toggle menu">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            <a href="<?= e($brandHref) ?>" class="topbar-brand">
                <span class="brand-mark"><?= e(strtoupper(substr($orgShort, 0, 2))) ?></span>
                <span><?= e($orgShort) ?></span>
            </a>

            <form class="topbar-search" method="get" action="<?= $isMember ? '/scms/portal/savings.php' : '/scms/members/index.php' ?>">
                <span class="search-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="7"/>
                        <path stroke-linecap="round" d="M21 21l-4.35-4.35"/>
                    </svg>
                </span>
                <input type="search" name="q" placeholder="<?= $isMember ? 'Search my transactions…' : 'Search members, loans, transactions…' ?>">
            </form>

            <div class="topbar-actions">

                <!-- Notifications -->
                <div class="dropdown" data-dropdown>
                    <button class="icon-btn" data-dropdown-toggle aria-label="Notifications">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>
                        <?php if ($unreadCount): ?>
                            <span class="dot-indicator"></span>
                        <?php endif; ?>
                    </button>
                    <div class="dropdown-menu">
                        <div class="dropdown-head">Notifications</div>
                        <?php if ($notifications): ?>
                            <?php foreach (array_slice($notifications, 0, 6) as $n): ?>
                                <a href="/scms/notification-view.php?key=<?= urlencode($n['key']) ?>"
                                   class="notif-item <?= !empty($n['unread']) ? 'unread' : '' ?>"
                                   style="text-decoration:none;color:inherit;">
                                    <div class="notif-icon"><?= e($n['icon']) ?></div>
                                    <div class="notif-body">
                                        <div class="notif-title"><?= e($n['title']) ?></div>
                                        <div class="notif-text"><?= e($n['text']) ?></div>
                                        <div class="notif-time"><?= e($n['time']) ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding:24px 14px;text-align:center;" class="muted">No notifications</div>
                        <?php endif; ?>
                        <a href="/scms/notifications.php" class="dropdown-item" style="justify-content:center;color:var(--primary);font-weight:600;">
                            View all notifications
                        </a>
                    </div>
                </div>

                <!-- Quick actions (staff only) -->
                <?php if (!$isMember): ?>
                    <div class="dropdown" data-dropdown>
                        <button class="icon-btn" data-dropdown-toggle aria-label="Quick actions">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" d="M12 4v16m8-8H4"/>
                            </svg>
                        </button>
                        <div class="dropdown-menu" style="min-width: 320px;">
                            <div class="dropdown-head">Quick actions</div>
                            <div class="quick-actions" style="padding: 6px;">
                                <a href="/scms/members/create.php" class="quick-action">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" d="M12 4v16m8-8H4"/>
                                    </svg>
                                    Add member
                                </a>
                                <a href="/scms/savings/deposit.php" class="quick-action">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m0 0l-4-4m4 4l4-4"/>
                                    </svg>
                                    Deposit
                                </a>
                                <a href="/scms/loans/create.php" class="quick-action">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    New loan
                                </a>
                                <a href="/scms/loans/repay.php" class="quick-action">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m0 0l4-4m-4 4l-4-4"/>
                                    </svg>
                                    Repayment
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- User menu -->
                <div class="dropdown" data-dropdown>
                    <button class="icon-btn" data-dropdown-toggle style="width:auto;padding:3px;" aria-label="Account">
                        <span class="avatar"><?= e($initial) ?></span>
                    </button>
                    <div class="dropdown-menu" style="min-width: 260px;">
                        <div class="user-info-head">
                            <span class="avatar"><?= e($initial) ?></span>
                            <div>
                                <div class="name"><?= e($user['full_name']) ?></div>
                                <div class="role"><?= e($user['role']) ?> · <?= e($user['username']) ?></div>
                            </div>
                        </div>
                        <a href="<?= $isMember ? '/scms/portal/profile.php' : '/scms/profile.php' ?>" class="dropdown-item">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                            My profile
                        </a>
                        <?php if (!$isMember): ?>
                            <a href="/scms/settings.php" class="dropdown-item">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                Settings
                            </a>
                        <?php endif; ?>
                        <button class="dropdown-item" id="themeToggle">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                            </svg>
                            Toggle theme
                        </button>
                        <div style="height:1px;background:var(--border-2);margin:6px 8px;"></div>
                        <a href="/scms/auth/logout.php" class="dropdown-item danger">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                            Log out
                        </a>
                    </div>
                </div>

            </div>
        </header>

        <main class="page">