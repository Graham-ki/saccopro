<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/audit.php';

// Load settings once per request — every page gets $SETTINGS
$SETTINGS = load_settings($pdo);

// Apply timezone from settings if defined
if (!empty($SETTINGS['timezone'])) {
    @date_default_timezone_set($SETTINGS['timezone']);
}

/** Require an approved logged-in user. */
function require_login(): array {
    if (empty($_SESSION['user_id'])) {
        flash('error', 'Please log in first.');
        redirect('/scms/auth/login.php');
    }
    return current_user();
}

/** Require an admin user. */
function require_admin(): array {
    $user = require_login();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die('Admin access only.');
    }
    return $user;
}

/** Require a logged-in member (role='member' with a linked member record). */
function require_member(): array {
    $user = require_login();
    if (($user['role'] ?? '') !== 'member' || empty($user['member_id'])) {
        http_response_code(403);
        die('Member access only.');
    }
    return $user;
}

/**
 * Get the currently logged-in user.
 * Includes member_id so portal checks can determine role+link in one query.
 */
function current_user(): array {
    global $pdo;
    static $cached = null;
    if ($cached) return $cached;

    $stmt = $pdo->prepare("
        SELECT id, username, email, full_name, role, status, member_id
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'approved') {
        session_destroy();
        redirect('/scms/auth/login.php');
    }
    return $cached = $user;
}

/**
 * Fetch the linked member record for a member-role user.
 * Returns the member row joined with their savings account info.
 */
function current_member(): array {
    global $pdo;
    $user = require_member();
    static $cached = null;
    if ($cached && (int)$cached['id'] === (int)$user['member_id']) return $cached;

    $stmt = $pdo->prepare("
        SELECT m.*, sa.id AS account_id, sa.account_no, sa.balance
        FROM members m
        LEFT JOIN savings_accounts sa ON sa.member_id = m.id
        WHERE m.id = ?
        ORDER BY sa.id DESC
        LIMIT 1
    ");
    $stmt->execute([(int)$user['member_id']]);
    $m = $stmt->fetch();

    if (!$m) {
        session_destroy();
        redirect('/scms/auth/login.php');
    }
    return $cached = $m;
}