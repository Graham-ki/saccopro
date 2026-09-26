<?php
declare(strict_types=1);

/** Sum of shares held by a member (excluding reversed purchases). */
function member_shares_held(PDO $pdo, int $memberId, ?string $asOf = null): int {
    $asOf = $asOf ?? date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(qty), 0)
        FROM share_purchases
        WHERE member_id = ?
          AND purchase_date <= ?
          AND is_reversed = 0
          AND (reversal_of_id IS NULL OR reversal_of_id = 0)
    ");
    $stmt->execute([$memberId, $asOf]);
    return (int)$stmt->fetchColumn();
}

/** Total share capital (value) held across all members. */
function total_share_capital(PDO $pdo): float {
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(total_amount), 0)
        FROM share_purchases
        WHERE is_reversed = 0
          AND (reversal_of_id IS NULL OR reversal_of_id = 0)
    ");
    return (float)$stmt->fetchColumn();
}

/** Total shares outstanding (count of units). */
function total_shares_outstanding(PDO $pdo): int {
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(qty), 0)
        FROM share_purchases
        WHERE is_reversed = 0
          AND (reversal_of_id IS NULL OR reversal_of_id = 0)
    ");
    return (int)$stmt->fetchColumn();
}

/**
 * Number of complete months between two dates.
 */
function months_between(string $from, string $to): int {
    $f = new DateTime($from);
    $t = new DateTime($to);
    $diff = $f->diff($t);
    return $diff->y * 12 + $diff->m;
}

/**
 * Record a share purchase.
 * If method = 'savings', atomically withdraws from the member's savings account
 * and links the resulting transaction to the share purchase.
 *
 * Race-safe; will fail if savings balance is insufficient.
 */
function record_share_purchase(
    PDO $pdo,
    int $memberId,
    int $qty,
    string $purchaseDate,
    string $method,
    ?string $notes,
    int $userId
): array {
    if ($qty <= 0) throw new InvalidArgumentException('Quantity must be positive.');

    $unitPrice = (float)(setting('share_face_value') ?? '10000');
    if ($unitPrice <= 0) throw new RuntimeException('Share face value is not configured.');

    $total = $qty * $unitPrice;

    $pdo->beginTransaction();
    try {
        // Lock member
        $stmt = $pdo->prepare("SELECT id, status, first_name, last_name FROM members WHERE id = ? FOR UPDATE");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();
        if (!$member) throw new RuntimeException('Member not found.');
        if ($member['status'] !== 'active') {
            throw new RuntimeException('Only active members can purchase shares.');
        }

        $savingsTxnId = null;

        // If paying from savings, withdraw from their account
        if ($method === 'savings') {
            $acc = $pdo->prepare("
                SELECT id, balance FROM savings_accounts
                WHERE member_id = ? LIMIT 1 FOR UPDATE
            ");
            $acc->execute([$memberId]);
            $account = $acc->fetch();
            if (!$account) throw new RuntimeException('Member has no savings account.');
            if ((float)$account['balance'] < $total) {
                throw new RuntimeException(
                    'Insufficient savings. Required: ' . money($total) .
                    ' · Available: ' . money((float)$account['balance'])
                );
            }

            // Post withdrawal via the existing helper for consistency
            $result = record_savings_transaction(
                $pdo,
                (int)$account['id'],
                'withdrawal',
                $total,
                $purchaseDate,
                'Share purchase (' . $qty . ' × ' . money($unitPrice) . ')',
                $userId
            );
            $savingsTxnId = (int)$result['id'];
        }

        // Insert share purchase
        $receiptNo = 'SHP-' . date('Ymd', strtotime($purchaseDate)) . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("
            INSERT INTO share_purchases
            (member_id, qty, unit_price, total_amount, purchase_date, method,
             receipt_no, savings_txn_id, notes, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $memberId, $qty, $unitPrice, $total, $purchaseDate, $method,
            $receiptNo, $savingsTxnId, $notes, $userId,
        ]);
        $purchaseId = (int)$pdo->lastInsertId();

        $pdo->commit();

        return [
            'id'              => $purchaseId,
            'receipt_no'      => $receiptNo,
            'qty'             => $qty,
            'unit_price'      => $unitPrice,
            'total'           => $total,
            'savings_txn_id'  => $savingsTxnId,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Reverse a share purchase.
 * If it was paid from savings, also reverse the linked savings transaction.
 */
function reverse_share_purchase(
    PDO $pdo,
    int $purchaseId,
    int $userId,
    string $reason
): array {
    if (trim($reason) === '') {
        throw new InvalidArgumentException('A reversal reason is required.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM share_purchases WHERE id = ? FOR UPDATE");
        $stmt->execute([$purchaseId]);
        $p = $stmt->fetch();
        if (!$p) throw new RuntimeException('Purchase not found.');
        if ((int)$p['is_reversed'] === 1) throw new RuntimeException('Already reversed.');
        if ((int)$p['reversal_of_id'] > 0) throw new RuntimeException('Cannot reverse a reversal.');

        // Reverse the linked savings transaction if applicable
        if ((int)$p['savings_txn_id'] > 0) {
            require_once __DIR__ . '/reversals.php';
            reverse_savings_transaction($pdo, (int)$p['savings_txn_id'], $userId,
                'Reversal of share purchase #' . $purchaseId . ' — ' . $reason);
        }

        // Insert counter-entry for the share purchase
        $receiptNo = 'SHP-REV-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("
            INSERT INTO share_purchases
            (member_id, qty, unit_price, total_amount, purchase_date, method,
             receipt_no, notes, recorded_by, reversal_of_id)
            VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int)$p['member_id'],
            -1 * (int)$p['qty'],
            (float)$p['unit_price'],
            -1 * (float)$p['total_amount'],
            $p['method'],
            $receiptNo,
            'Reversal of #' . $purchaseId . ' — ' . $reason,
            $userId,
            $purchaseId,
        ]);
        $reversalId = (int)$pdo->lastInsertId();

        // Mark original reversed
        $pdo->prepare("
            UPDATE share_purchases
            SET is_reversed = 1, reversed_by_id = ?
            WHERE id = ?
        ")->execute([$userId, $purchaseId]);

        $pdo->commit();
        return ['reversal_id' => $reversalId, 'receipt_no' => $receiptNo];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Compute the interest earned in a given year (used as default profit pool).
 */
function computed_year_interest(PDO $pdo, int $year): float {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(interest_portion), 0)
        FROM loan_repayments
        WHERE is_reversed = 0
          AND (reversal_of_id IS NULL OR reversal_of_id = 0)
          AND YEAR(payment_date) = ?
    ");
    $stmt->execute([$year]);
    return (float)$stmt->fetchColumn();
}

/**
 * Build the dividend eligibility + payout preview for a given year.
 * Returns:
 *   [
 *     'rows'                  => [ ['member_id','name','member_no','join_date',
 *                                   'months_membership','shares_held','eligible',
 *                                   'ineligible_reason','amount'], ... ],
 *     'total_eligible_shares' => int,
 *     'distributable_amount'  => float,
 *     'total_payout'          => float,
 *   ]
 */
function preview_dividend(
    PDO $pdo,
    int $year,
    float $profitPool,
    float $distributablePct,
    int $minMonths
): array {
    $distributable = round($profitPool * $distributablePct / 100, 2);
    $asOf = "$year-12-31";

    // All members (active OR inactive at year-end — we compute eligibility per member)
    $members = $pdo->query("
        SELECT id, member_no, first_name, last_name, join_date, status
        FROM members
        ORDER BY first_name, last_name
    ")->fetchAll();

    $rows = [];
    $totalEligibleShares = 0;

    foreach ($members as $m) {
        $shares = member_shares_held($pdo, (int)$m['id'], $asOf);
        $months = months_between((string)$m['join_date'], $asOf);

        // Member must still be active at year-end
        $eligible   = true;
        $reason     = null;

        if ($m['status'] !== 'active') {
            $eligible = false;
            $reason = 'Not active';
        } elseif ($months < $minMonths) {
            $eligible = false;
            $reason = "Membership under {$minMonths} months";
        } elseif ($shares <= 0) {
            $eligible = false;
            $reason = 'No shares held';
        }

        if ($eligible) $totalEligibleShares += $shares;

        $rows[] = [
            'member_id'         => (int)$m['id'],
            'name'              => $m['first_name'] . ' ' . $m['last_name'],
            'member_no'         => $m['member_no'],
            'join_date'         => $m['join_date'],
            'months_membership' => $months,
            'shares_held'       => $shares,
            'eligible'          => $eligible,
            'ineligible_reason' => $reason,
            'amount'            => 0.0,
        ];
    }

    // Compute payouts
    if ($totalEligibleShares > 0 && $distributable > 0) {
        $allocated = 0.0;
        foreach ($rows as &$r) {
            if (!$r['eligible'] || $r['shares_held'] <= 0) continue;
            $amt = round($distributable * ($r['shares_held'] / $totalEligibleShares), 2);
            $r['amount'] = $amt;
            $allocated += $amt;
        }
        unset($r);

        // Absorb rounding difference into the largest payout
        $diff = round($distributable - $allocated, 2);
        if (abs($diff) > 0.001) {
            $maxIdx = null; $maxShares = -1;
            foreach ($rows as $i => $r) {
                if ($r['eligible'] && $r['shares_held'] > $maxShares) {
                    $maxShares = $r['shares_held'];
                    $maxIdx = $i;
                }
            }
            if ($maxIdx !== null) $rows[$maxIdx]['amount'] += $diff;
        }
    }

    $totalPayout = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));

    return [
        'rows'                  => $rows,
        'total_eligible_shares' => $totalEligibleShares,
        'distributable_amount'  => $distributable,
        'total_payout'          => round($totalPayout, 2),
    ];
}

/**
 * Declare a dividend: compute all payouts, save dividend + payout rows.
 * Status starts as 'declared'. Call pay_dividend() to post the savings transactions.
 */
function declare_dividend(
    PDO $pdo,
    int $year,
    float $computedInterest,
    ?float $overrideProfit,
    float $distributablePct,
    int $minMonths,
    ?string $basisNote,
    int $userId
): int {
    $profitPool = $overrideProfit !== null ? $overrideProfit : $computedInterest;

    if ($profitPool < 0) throw new RuntimeException('Profit pool cannot be negative.');
    if ($distributablePct < 0 || $distributablePct > 100) {
        throw new RuntimeException('Distribution percentage must be between 0 and 100.');
    }

    // Check no dividend for the same year
    $exists = $pdo->prepare("SELECT id FROM dividends WHERE year = ? LIMIT 1");
    $exists->execute([$year]);
    if ($exists->fetch()) {
        throw new RuntimeException("A dividend for {$year} already exists.");
    }

    $preview = preview_dividend($pdo, $year, $profitPool, $distributablePct, $minMonths);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO dividends
            (year, computed_interest, override_profit, profit_pool,
             distributable_pct, distributable_amount, min_membership_months,
             total_eligible_shares, status, basis_note, declared_by, declared_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'declared', ?, ?, NOW())
        ");
        $stmt->execute([
            $year, $computedInterest, $overrideProfit, $profitPool,
            $distributablePct, $preview['distributable_amount'], $minMonths,
            $preview['total_eligible_shares'], $basisNote, $userId,
        ]);
        $dividendId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("
            INSERT INTO dividend_payouts
            (dividend_id, member_id, shares_held, months_membership,
             eligible, ineligible_reason, amount)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($preview['rows'] as $r) {
            // Skip completely ineligible members to keep the table small
            if (!$r['eligible'] && $r['shares_held'] <= 0) continue;

            $ins->execute([
                $dividendId,
                $r['member_id'],
                $r['shares_held'],
                $r['months_membership'],
                $r['eligible'] ? 1 : 0,
                $r['ineligible_reason'],
                $r['amount'],
            ]);
        }

        $pdo->commit();
        return $dividendId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Pay a declared dividend: post a savings deposit for each eligible payout.
 * Idempotent — skips payouts already marked paid.
 */
function pay_dividend(PDO $pdo, int $dividendId, int $userId, string $method = 'savings'): array {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM dividends WHERE id = ? FOR UPDATE");
        $stmt->execute([$dividendId]);
        $div = $stmt->fetch();
        if (!$div) throw new RuntimeException('Dividend not found.');
        if ($div['status'] === 'paid') throw new RuntimeException('Dividend already paid.');
        if ($div['status'] === 'cancelled') throw new RuntimeException('Dividend was cancelled.');

        // Fetch unpaid payouts
        $payouts = $pdo->prepare("
            SELECT dp.*, m.first_name, m.last_name, sa.id AS account_id
            FROM dividend_payouts dp
            JOIN members m ON m.id = dp.member_id
            LEFT JOIN savings_accounts sa ON sa.member_id = m.id
            WHERE dp.dividend_id = ? AND dp.paid_at IS NULL AND dp.amount > 0 AND dp.eligible = 1
            ORDER BY dp.id
        ");
        $payouts->execute([$dividendId]);
        $list = $payouts->fetchAll();

        $posted = 0;
        $totalPaid = 0.0;

        foreach ($list as $p) {
            if ($method === 'savings') {
                if (!$p['account_id']) continue; // no savings account, skip

                $ref = 'DIV-' . $div['year'] . '-' . str_pad((string)$p['member_id'], 4, '0', STR_PAD_LEFT);
                $result = record_savings_transaction(
                    $pdo,
                    (int)$p['account_id'],
                    'deposit',
                    (float)$p['amount'],
                    date('Y-m-d'),
                    'Dividend for ' . $div['year'] . ' (' . $p['shares_held'] . ' shares)',
                    $userId
                );

                $pdo->prepare("
                    UPDATE dividend_payouts
                    SET savings_txn_id = ?, paid_at = NOW(), paid_by = ?, payment_method = ?
                    WHERE id = ?
                ")->execute([(int)$result['id'], $userId, $method, (int)$p['id']]);
            } else {
                $pdo->prepare("
                    UPDATE dividend_payouts
                    SET paid_at = NOW(), paid_by = ?, payment_method = ?
                    WHERE id = ?
                ")->execute([$userId, $method, (int)$p['id']]);
            }

            $posted++;
            $totalPaid += (float)$p['amount'];
        }

        // Update dividend aggregate
        $pdo->prepare("
            UPDATE dividends
            SET status = 'paid', total_paid = ?
            WHERE id = ?
        ")->execute([$totalPaid, $dividendId]);

        $pdo->commit();
        return ['posted' => $posted, 'total_paid' => $totalPaid];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}