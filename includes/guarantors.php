<?php
declare(strict_types=1);

/**
 * Add a guarantor to a loan.
 * Enforces:
 *   - loan exists and is active
 *   - member exists and is active
 *   - member is not the borrower
 *   - member not already a guarantor on this loan
 *   - member's total guarantees don't exceed their allowed cap
 */
function add_guarantor(PDO $pdo, int $loanId, int $memberId, float $amount, ?string $notes, int $userId): int
{
    if ($amount <= 0) throw new InvalidArgumentException('Guarantee amount must be positive.');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM loans WHERE id = ? FOR UPDATE");
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch();
        if (!$loan) throw new RuntimeException('Loan not found.');
        if ($loan['status'] !== 'active') throw new RuntimeException('Guarantors can only be added to active loans.');
        if ((int)$loan['member_id'] === $memberId) throw new RuntimeException('The borrower cannot be their own guarantor.');

        $stmt = $pdo->prepare("SELECT id, status, first_name, last_name FROM members WHERE id = ? LIMIT 1");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();
        if (!$member) throw new RuntimeException('Member not found.');
        if ($member['status'] !== 'active') throw new RuntimeException('Only active members can act as guarantors.');

        $stmt = $pdo->prepare("SELECT id FROM loan_guarantors WHERE loan_id = ? AND member_id = ? LIMIT 1");
        $stmt->execute([$loanId, $memberId]);
        if ($stmt->fetch()) throw new RuntimeException('This member is already a guarantor on this loan.');

        // ==== DEBUG: catch where the error comes from ====
        try {
            $guaranteedTotal = (float)$pdo->query("
                SELECT COALESCE(SUM(amount_guaranteed), 0)
                FROM loan_guarantors
                WHERE member_id = " . (int)$memberId . " AND status = 'active'
            ")->fetchColumn();
            error_log('[guarantors:add] guaranteedTotal OK: ' . $guaranteedTotal);
        } catch (Throwable $e) {
            error_log('[guarantors:add] guaranteedTotal FAILED: ' . $e->getMessage());
            throw $e;
        }

        try {
            $savings = (float)$pdo->query("
                SELECT COALESCE(balance, 0) FROM savings_accounts WHERE member_id = " . (int)$memberId . " LIMIT 1
            ")->fetchColumn();
            error_log('[guarantors:add] savings OK: ' . $savings);
        } catch (Throwable $e) {
            error_log('[guarantors:add] savings FAILED: ' . $e->getMessage());
            throw $e;
        }
        // ================================================

        $cap = $savings;
        if ($guaranteedTotal + $amount > $cap + 0.001) {
            throw new RuntimeException('Amount has exceeded the guarantor\'s capacity.');
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO loan_guarantors
                (loan_id, member_id, amount_guaranteed, status, notes, added_by)
                VALUES (?, ?, ?, 'active', ?, ?)
            ");
            $stmt->execute([$loanId, $memberId, $amount, $notes, $userId]);
            $id = (int)$pdo->lastInsertId();
            error_log('[guarantors:add] INSERT OK id=' . $id);
        } catch (Throwable $e) {
            error_log('[guarantors:add] INSERT FAILED: ' . $e->getMessage());
            throw $e;
        }

        $pdo->commit();
        return $id;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Release a guarantor. Marks status='released' with a reason and audit trail.
 * Safe against double-release.
 */
function release_guarantor(
    PDO $pdo,
    int $guarantorId,
    int $userId,
    string $reason
): void {
    if (trim($reason) === '') {
        throw new InvalidArgumentException('A release reason is required.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM loan_guarantors WHERE id = ? FOR UPDATE");
        $stmt->execute([$guarantorId]);
        $g = $stmt->fetch();
        if (!$g) throw new RuntimeException('Guarantor record not found.');
        if ($g['status'] !== 'active') {
            throw new RuntimeException('This guarantee is already ' . $g['status'] . '.');
        }

        $pdo->prepare("
            UPDATE loan_guarantors
            SET status = 'released', released_by = ?, released_at = NOW(), release_reason = ?
            WHERE id = ?
        ")->execute([$userId, $reason, $guarantorId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Auto-release all active guarantees for a loan (called when loan completes). */
function release_all_guarantors_for_loan(PDO $pdo, int $loanId, int $userId): int {
    $stmt = $pdo->prepare("
        UPDATE loan_guarantors
        SET status = 'released', released_by = ?, released_at = NOW(),
            release_reason = 'Loan completed'
        WHERE loan_id = ? AND status = 'active'
    ");
    $stmt->execute([$userId, $loanId]);
    return $stmt->rowCount();
}

/** Fetch all active guarantees held by a member with loan info. */
function member_active_guarantees(PDO $pdo, int $memberId): array {
    $stmt = $pdo->prepare("
        SELECT g.*, l.loan_no, l.status AS loan_status, l.balance AS loan_balance,
               m.id AS borrower_id, m.first_name, m.last_name, m.member_no
        FROM loan_guarantors g
        JOIN loans l ON l.id = g.loan_id
        JOIN members m ON m.id = l.member_id
        WHERE g.member_id = ? AND g.status = 'active'
        ORDER BY g.created_at DESC
    ");
    $stmt->execute([$memberId]);
    return $stmt->fetchAll();
}

/** Sum of active guarantees held by a member. */
function member_guarantee_total(PDO $pdo, int $memberId): float {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_guaranteed), 0)
        FROM loan_guarantors
        WHERE member_id = ? AND status = 'active'
    ");
    $stmt->execute([$memberId]);
    return (float)$stmt->fetchColumn();
}