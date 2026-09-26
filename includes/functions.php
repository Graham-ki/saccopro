<?php
declare(strict_types=1);
// includes/functions.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never {
    header("Location: $path");
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): void {
    if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        die('Invalid CSRF token.');
    }
}

function flash(string $key, ?string $msg = null): ?string {
    if ($msg !== null) {
        $_SESSION['flash'][$key] = $msg;
        return null;
    }
    $val = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $val;
}

/** Generate next member number like M-0001, M-0002 … */
function next_member_no(PDO $pdo): string {
    $row = $pdo->query("SELECT member_no FROM members ORDER BY id DESC LIMIT 1")->fetch();
    $next = 1;
    if ($row && preg_match('/(\d+)$/', $row['member_no'], $m)) {
        $next = (int)$m[1] + 1;
    }
    return 'M-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/** Generate next savings account number like SA-0001 */
function next_account_no(PDO $pdo): string {
    $row = $pdo->query("SELECT account_no FROM savings_accounts ORDER BY id DESC LIMIT 1")->fetch();
    $next = 1;
    if ($row && preg_match('/(\d+)$/', $row['account_no'], $m)) {
        $next = (int)$m[1] + 1;
    }
    return 'SA-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/** Simple pagination data struct. */
function paginate(int $total, int $perPage, int $current): array {
    $pages = max(1, (int)ceil($total / $perPage));
    $current = max(1, min($current, $pages));
    return [
        'total'    => $total,
        'per_page' => $perPage,
        'current'  => $current,
        'pages'    => $pages,
        'offset'   => ($current - 1) * $perPage,
    ];
}

/** Build query string preserving current GET params, overriding some. */
function qs(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    return '?' . http_build_query($params);
}
/** Generate next savings transaction reference: TXN-20250101-0001 */
function next_txn_reference(PDO $pdo): string {
    $today = date('Ymd');
    $prefix = "TXN-$today-";
    $row = $pdo->prepare("SELECT reference FROM savings_transactions
                          WHERE reference LIKE ? ORDER BY id DESC LIMIT 1");
    $row->execute([$prefix . '%']);
    $last = $row->fetchColumn();
    $next = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) $next = (int)$m[1] + 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/**
 * Record a savings transaction — the ONLY safe way to change an account balance.
 * Wraps everything in a DB transaction and locks the row.
 */
function record_savings_transaction(
    PDO $pdo,
    int $accountId,
    string $type,
    float $amount,
    string $txnDate,
    ?string $notes,
    int $userId
): array {
    if (!in_array($type, ['deposit', 'withdrawal'], true)) {
        throw new InvalidArgumentException('Invalid transaction type.');
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('Amount must be greater than zero.');
    }

    // If we're already inside a transaction (e.g. called from share purchase),
    // join it — do not start a nested one.
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // Lock the account row
        $lock = $pdo->prepare("SELECT id, balance FROM savings_accounts WHERE id = ? FOR UPDATE");
        $lock->execute([$accountId]);
        $account = $lock->fetch();
        if (!$account) throw new RuntimeException('Savings account not found.');

        $current    = (float)$account['balance'];
        $newBalance = $type === 'deposit' ? $current + $amount : $current - $amount;

        if ($type === 'withdrawal' && $newBalance < 0) {
            throw new RuntimeException('Insufficient balance. Available: ' . money($current));
        }

        // Reference
        $reference = next_txn_reference($pdo);

        // Insert ledger entry
        $stmt = $pdo->prepare("
            INSERT INTO savings_transactions
            (account_id, type, amount, balance_after, reference, transaction_date, notes, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $accountId, $type, $amount, $newBalance, $reference,
            $txnDate, $notes, $userId,
        ]);
        $txnId = (int)$pdo->lastInsertId();

        // Update cached balance
        $pdo->prepare("UPDATE savings_accounts SET balance = ? WHERE id = ?")
            ->execute([$newBalance, $accountId]);

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'id'            => $txnId,
            'reference'     => $reference,
            'balance_after' => $newBalance,
            'previous'      => $current,
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
/** Look up a savings account by member id. */
function account_for_member(PDO $pdo, int $memberId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM savings_accounts WHERE member_id = ? LIMIT 1");
    $stmt->execute([$memberId]);
    return $stmt->fetch() ?: null;
}
/** Next loan number: L-0001 */
function next_loan_no(PDO $pdo): string {
    $row = $pdo->query("SELECT loan_no FROM loans ORDER BY id DESC LIMIT 1")->fetch();
    $next = 1;
    if ($row && preg_match('/(\d+)$/', $row['loan_no'], $m)) $next = (int)$m[1] + 1;
    return 'L-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/** Next repayment reference: RPY-20250101-0001 */
function next_repayment_reference(PDO $pdo): string {
    $today = date('Ymd');
    $prefix = "RPY-$today-";
    $stmt = $pdo->prepare("SELECT reference FROM loan_repayments WHERE reference LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) $next = (int)$m[1] + 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/** Add months to a date, clamping day overflow (Jan 31 + 1mo → Feb 28). */
function add_months(string $date, int $months): string {
    $d = new DateTime($date);
    $day = (int)$d->format('d');
    $d->setDate((int)$d->format('Y'), (int)$d->format('n'), 1);
    $d->modify("+$months months");
    $lastDay = (int)$d->format('t');
    $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, $lastDay));
    return $d->format('Y-m-d');
}

/**
 * Build a loan schedule.
 * Returns: ['schedule' => [...], 'total_interest' => float, 'total_payable' => float, 'monthly_installment' => float]
 *
 * @param string $method 'flat' | 'declining'
 * @param float  $rate   Monthly interest rate in percent (e.g. 3 for 3%/mo)
 */
function build_loan_schedule(
    float $principal,
    float $monthlyRatePct,
    int $termMonths,
    string $firstDueDate,
    string $method = 'declining'
): array {
    if ($principal <= 0) throw new InvalidArgumentException('Principal must be positive.');
    if ($termMonths <= 0) throw new InvalidArgumentException('Term must be at least 1 month.');

    $schedule = [];
    $totalInterest = 0.0;
    $r = $monthlyRatePct / 100;

    if ($method === 'flat') {
        // Interest computed on the full principal for the whole term
        $totalInterest = round($principal * $r * $termMonths, 2);
        $totalPayable  = round($principal + $totalInterest, 2);
        $principalPart = round($principal / $termMonths, 2);
        $interestPart  = round($totalInterest / $termMonths, 2);

        $runningP = $principal;
        $runningI = $totalInterest;

        for ($i = 1; $i <= $termMonths; $i++) {
            $p = ($i === $termMonths) ? round($runningP, 2) : $principalPart;
            $iAmt = ($i === $termMonths) ? round($runningI, 2) : $interestPart;
            $runningP -= $p;
            $runningI -= $iAmt;

            $schedule[] = [
                'installment_no' => $i,
                'due_date'       => add_months($firstDueDate, $i - 1),
                'principal_due'  => $p,
                'interest_due'   => $iAmt,
                'total_due'      => round($p + $iAmt, 2),
            ];
        }
        $monthlyInstallment = round($schedule[0]['total_due'], 2);
    } else {
        // Declining balance — EMI = P * r * (1+r)^n / ((1+r)^n - 1)
        if ($r == 0) {
            $emi = round($principal / $termMonths, 2);
        } else {
            $factor = pow(1 + $r, $termMonths);
            $emi = round($principal * $r * $factor / ($factor - 1), 2);
        }

        $remaining = $principal;
        for ($i = 1; $i <= $termMonths; $i++) {
            $interest = round($remaining * $r, 2);
            $principalPart = $emi - $interest;

            // Last installment: absorb rounding
            if ($i === $termMonths) {
                $principalPart = round($remaining, 2);
                $emi = round($principalPart + $interest, 2);
            }

            $schedule[] = [
                'installment_no' => $i,
                'due_date'       => add_months($firstDueDate, $i - 1),
                'principal_due'  => round($principalPart, 2),
                'interest_due'   => $interest,
                'total_due'      => round($principalPart + $interest, 2),
            ];
            $remaining = round($remaining - $principalPart, 2);
            $totalInterest += $interest;
        }
        $totalInterest = round($totalInterest, 2);
        $totalPayable  = round($principal + $totalInterest, 2);
        $monthlyInstallment = $emi;
    }

    return [
        'schedule'            => $schedule,
        'total_interest'      => $totalInterest,
        'total_payable'       => $totalPayable,
        'monthly_installment' => $monthlyInstallment,
    ];
}

/**
 * Allocate a repayment across installments (oldest first), updating
 * loan_schedules rows and returning a breakdown.
 */
function allocate_repayment(PDO $pdo, int $loanId, float $amount, string $paymentDate): array {
    if ($amount <= 0) throw new InvalidArgumentException('Repayment amount must be positive.');

    // Fetch unpaid/partial installments oldest first
    $stmt = $pdo->prepare("
        SELECT * FROM loan_schedules
        WHERE loan_id = ? AND status IN ('pending','partial','overdue')
        ORDER BY installment_no ASC
    ");
    $stmt->execute([$loanId]);
    $installments = $stmt->fetchAll();

    $remaining = $amount;
    $principalPaid = 0.0;
    $interestPaid  = 0.0;

    foreach ($installments as $row) {
        if ($remaining <= 0) break;

        $due       = (float)$row['total_due'];
        $already   = (float)$row['amount_paid'];
        $left      = round($due - $already, 2);
        if ($left <= 0) continue;

        $pay = min($remaining, $left);
        $remaining = round($remaining - $pay, 2);

        // Proportion principal/interest based on this installment
        $ratioP = $due > 0 ? (float)$row['principal_due'] / $due : 1;
        $ratioI = 1 - $ratioP;
        $pPart = round($pay * $ratioP, 2);
        $iPart = round($pay - $pPart, 2);
        $principalPaid += $pPart;
        $interestPaid  += $iPart;

        $newPaid = round($already + $pay, 2);
        $newStatus = $newPaid + 0.001 >= $due ? 'paid' : 'partial';
        $paidAt = $newStatus === 'paid' ? $paymentDate : null;

        $pdo->prepare("
            UPDATE loan_schedules
            SET amount_paid = ?, status = ?, paid_at = ?
            WHERE id = ?
        ")->execute([$newPaid, $newStatus, $paidAt, (int)$row['id']]);
    }

    return [
        'principal_portion' => round($principalPaid, 2),
        'interest_portion'  => round($interestPaid, 2),
    ];
}

/** Recompute a loan's aggregate fields from its schedule. */
function refresh_loan_totals(PDO $pdo, int $loanId): void {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(amount_paid), 0) AS paid,
            COALESCE(SUM(total_due), 0)   AS total_due
        FROM loan_schedules WHERE loan_id = ?
    ");
    $stmt->execute([$loanId]);
    $row = $stmt->fetch();

    $paid   = (float)$row['paid'];
    $loan   = $pdo->prepare("SELECT total_payable, principal FROM loans WHERE id = ?");
    $loan->execute([$loanId]);
    $l = $loan->fetch();
    $balance = round((float)$l['total_payable'] - $paid, 2);

    $status = 'active';
    if ($balance <= 0.001) $status = 'completed';

    $pdo->prepare("UPDATE loans SET amount_paid = ?, balance = ?, status = ? WHERE id = ?")
        ->execute([$paid, max(0, $balance), $status, $loanId]);
          if ($status === 'completed') {
        try {
            require_once __DIR__ . '/guarantors.php';
            // Use a system user id or the currently logged-in one
            $releaseBy = $_SESSION['user_id'] ?? 0;
            release_all_guarantors_for_loan($pdo, $loanId, (int)$releaseBy);
        } catch (Throwable $e) {
            error_log('[guarantors:auto-release] ' . $e->getMessage());
        }
    }
}
/** Human-friendly money format. Reads currency from settings. */
function money(float|string|null $amount, ?string $overrideSymbol = null): string {
    $symbol = $overrideSymbol;
    if ($symbol === null) {
        $symbol = function_exists('setting')
            ? (setting('currency_symbol') ?? 'UGX')
            : 'UGX';
    }

    $position = function_exists('setting')
        ? (setting('currency_position') ?? 'before')
        : 'before';

    $formatted = number_format((float)$amount, 0);

    return $position === 'after'
        ? $formatted . ' ' . $symbol
        : $symbol . ' ' . $formatted;
}