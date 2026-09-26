<?php
declare(strict_types=1);

/**
 * Build the notifications array for a user, excluding ones they've dismissed.
 *
 * Returns:
 *   [
 *     'items'  => [ ['key','icon','title','text','time','url','unread','category'], ... ],
 *     'unread' => int,
 *   ]
 */
function build_notifications(PDO $pdo, array $user): array {
    $items  = [];
    $unread = 0;

    // ---- 1. Pending user approvals (admin only) ----
    if ($user['role'] === 'admin') {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetchColumn();
        if ($n > 0) {
            $items[] = [
                'key'      => 'pending-approvals',
                'category' => 'users',
                'icon'     => '👤',
                'title'    => "{$n} pending user approval" . ($n === 1 ? '' : 's'),
                'text'     => 'New registrations waiting for review.',
                'time'     => 'Now',
                'url'      => '/scms/admin/users.php?status=pending',
                'unread'   => true,
            ];
            $unread++;
        }
    }
    // ---- Pending member requests ----
if ($user['role'] === 'admin') {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM member_requests WHERE status='pending'")->fetchColumn();
    if ($n > 0) {
        $items[] = [
            'key'      => 'pending-requests',
            'category' => 'requests',
            'icon'     => '📝',
            'title'    => "{$n} pending member request" . ($n === 1 ? '' : 's'),
            'text'     => 'Members are waiting for approval.',
            'time'     => 'Now',
            'url'      => '/scms/admin/requests.php',
            'unread'   => true,
        ];
        $unread++;
    }
}
    // ---- 2. Overdue loans ----
    $overdue = $pdo->query("
        SELECT l.id, l.loan_no, m.first_name, m.last_name,
               l.maturity_date, l.balance
        FROM loans l
        JOIN members m ON m.id = l.member_id
        WHERE l.status='active' AND l.maturity_date < CURDATE()
        ORDER BY l.maturity_date ASC
        LIMIT 5
    ")->fetchAll();

    foreach ($overdue as $o) {
        $days = (int)floor((time() - strtotime($o['maturity_date'])) / 86400);
        $items[] = [
            'key'      => 'overdue-loan-' . (int)$o['id'],
            'category' => 'loans',
            'icon'     => '⚠️',
            'title'    => 'Overdue: ' . $o['first_name'] . ' ' . $o['last_name'],
            'text'     => 'Loan ' . $o['loan_no'] . ' · ' . $days . ' day' . ($days === 1 ? '' : 's') .
                          ' past maturity · balance ' . money((float)$o['balance']),
            'time'     => $days . 'd late',
            'url'      => '/scms/loans/view.php?id=' . (int)$o['id'],
            'unread'   => true,
        ];
        $unread++;
    }

    // ---- 3. Dues within 7 days ----
    $dues = $pdo->query("
        SELECT ls.id, ls.due_date, ls.total_due, ls.amount_paid, ls.installment_no,
               l.id AS loan_id, l.loan_no, m.first_name, m.last_name
        FROM loan_schedules ls
        JOIN loans l ON l.id = ls.loan_id
        JOIN members m ON m.id = l.member_id
        WHERE ls.status IN ('pending','partial','overdue')
          AND l.status='active'
          AND ls.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY ls.due_date ASC
        LIMIT 5
    ")->fetchAll();

    foreach ($dues as $d) {
        $outstanding = max(0, (float)$d['total_due'] - (float)$d['amount_paid']);
        $isToday = $d['due_date'] === date('Y-m-d');
        $items[] = [
            'key'      => 'due-' . (int)$d['id'],
            'category' => 'loans',
            'icon'     => '⏰',
            'title'    => 'Due ' . ($isToday ? 'today' : date('M j', strtotime($d['due_date']))) .
                          ': ' . $d['first_name'] . ' ' . $d['last_name'],
            'text'     => 'Loan ' . $d['loan_no'] . ' · installment #' . (int)$d['installment_no'] .
                          ' · ' . money($outstanding),
            'time'     => $isToday ? 'Today' : date('M j', strtotime($d['due_date'])),
            'url'      => '/scms/loans/view.php?id=' . (int)$d['loan_id'],
            'unread'   => true,
        ];
        $unread++;
    }

    // ---- 4. Recent activity (savings + loans) ----
    $recent = $pdo->query("
        (SELECT 'savings' AS kind, st.id AS ref_id, st.created_at AS ts,
                CONCAT(m.first_name,' ',m.last_name) AS who,
                st.type AS subtype, st.amount, m.id AS member_id, NULL AS loan_id, NULL AS loan_no
         FROM savings_transactions st
         JOIN savings_accounts sa ON sa.id = st.account_id
         JOIN members m ON m.id = sa.member_id
         WHERE st.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         ORDER BY st.created_at DESC LIMIT 5)
        UNION ALL
        (SELECT 'loan' AS kind, l.id AS ref_id, l.created_at AS ts,
                CONCAT(m.first_name,' ',m.last_name) AS who,
                'issued' AS subtype, l.principal AS amount, m.id AS member_id,
                l.id AS loan_id, l.loan_no AS loan_no
         FROM loans l
         JOIN members m ON m.id = l.member_id
         WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         ORDER BY l.created_at DESC LIMIT 5)
        ORDER BY ts DESC
        LIMIT 5
    ")->fetchAll();

    foreach ($recent as $r) {
        $label = $r['kind'] === 'savings'
            ? ($r['subtype'] === 'deposit' ? 'Deposit' : 'Withdrawal')
            : 'Loan issued';
        $icon  = $r['kind'] === 'savings' ? '💰' : '📄';
        $url   = $r['kind'] === 'savings'
            ? '/scms/members/view.php?id=' . (int)$r['member_id']
            : '/scms/loans/view.php?id=' . (int)$r['loan_id'];

        $items[] = [
            'key'      => 'recent-' . $r['kind'] . '-' . (int)$r['ref_id'],
            'category' => $r['kind'],
            'icon'     => $icon,
            'title'    => $label . ' — ' . $r['who'],
            'text'     => money((float)$r['amount']),
            'time'     => date('M j, g:ia', strtotime((string)$r['ts'])),
            'url'      => $url,
            'unread'   => false,
        ];
    }

    // ---- Filter out dismissed items for this user ----
    if ($items) {
        $keys = array_column($items, 'key');
        $placeholders = implode(',', array_fill(0, count($keys), '?'));

        $dismissed = $pdo->prepare("
            SELECT notification_key FROM notification_dismissals
            WHERE user_id = ? AND notification_key IN ($placeholders)
        ");
        $dismissed->execute(array_merge([(int)$user['id']], $keys));
        $dismissedSet = $dismissed->fetchAll(PDO::FETCH_COLUMN);

        if ($dismissedSet) {
            $lookup = array_flip($dismissedSet);
            $items = array_values(array_filter($items, fn($i) => !isset($lookup[$i['key']])));

            // Recompute unread after filtering
            $unread = count(array_filter($items, fn($i) => !empty($i['unread'])));
        }
    }

    return ['items' => $items, 'unread' => $unread];
}

/** Dismiss a single notification for a user. */
function dismiss_notification(PDO $pdo, int $userId, string $key): void {
    $pdo->prepare("
        INSERT IGNORE INTO notification_dismissals (user_id, notification_key)
        VALUES (?, ?)
    ")->execute([$userId, $key]);
}

/** Dismiss many notifications at once. */
function dismiss_notifications(PDO $pdo, int $userId, array $keys): int {
    if (!$keys) return 0;
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO notification_dismissals (user_id, notification_key)
        VALUES (?, ?)
    ");
    $n = 0;
    foreach ($keys as $k) {
        if (!is_string($k) || $k === '') continue;
        $stmt->execute([$userId, $k]);
        $n += $stmt->rowCount();
    }
    return $n;
}