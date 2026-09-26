<?php
declare(strict_types=1);

/**
 * Create a member request. Validates type-specific fields.
 */
function create_member_request(PDO $pdo, int $memberId, string $type, array $data): int {
    $validTypes = ['deposit','withdrawal','loan','share_purchase'];
    if (!in_array($type, $validTypes, true)) {
        throw new InvalidArgumentException('Invalid request type.');
    }

    // Validate required fields per type
    if ($type === 'deposit' || $type === 'withdrawal') {
        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) throw new InvalidArgumentException('Amount must be positive.');
    }
    if ($type === 'share_purchase') {
        $qty = (int)($data['qty'] ?? 0);
        if ($qty <= 0) throw new InvalidArgumentException('Quantity must be at least 1.');
    }
    if ($type === 'loan') {
        $amount = (float)($data['amount'] ?? 0);
        $term   = (int)($data['term_months'] ?? 0);
        if ($amount <= 0) throw new InvalidArgumentException('Loan amount must be positive.');
        if ($term < 1) throw new InvalidArgumentException('Term must be at least 1 month.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO member_requests
        (member_id, type, amount, qty, term_months, interest_rate, interest_method, purpose, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $memberId,
        $type,
        isset($data['amount'])          ? (float)$data['amount']          : null,
        isset($data['qty'])             ? (int)$data['qty']               : null,
        isset($data['term_months'])     ? (int)$data['term_months']       : null,
        isset($data['interest_rate'])   ? (float)$data['interest_rate']   : null,
        $data['interest_method'] ?? null,
        $data['purpose'] ?? null,
        $data['notes'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Approve a request. Executes the side-effect atomically.
 * Returns ['entity_id' => int, 'summary' => string].
 */
function approve_member_request(PDO $pdo, int $requestId, int $adminId): array {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM member_requests WHERE id = ? FOR UPDATE");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();
        if (!$req) throw new RuntimeException('Request not found.');
        if ($req['status'] !== 'pending') {
            throw new RuntimeException('Request is already ' . $req['status'] . '.');
        }

        $memberId   = (int)$req['member_id'];
        $entityId   = 0;
        $summary    = '';
        $date       = date('Y-m-d');

        switch ($req['type']) {
            case 'deposit':
                $acc = $pdo->prepare("SELECT id FROM savings_accounts WHERE member_id = ? LIMIT 1");
                $acc->execute([$memberId]);
                $accountId = (int)$acc->fetchColumn();
                if (!$accountId) throw new RuntimeException('Member has no savings account.');

                $result = record_savings_transaction(
                    $pdo, $accountId, 'deposit',
                    (float)$req['amount'], $date,
                    'Member portal request #' . $requestId .
                        ($req['notes'] ? ' — ' . $req['notes'] : ''),
                    $adminId
                );
                $entityId = (int)$result['id'];
                $summary = 'Deposit posted · ref ' . $result['reference'];
                break;

            case 'withdrawal':
                $acc = $pdo->prepare("SELECT id FROM savings_accounts WHERE member_id = ? LIMIT 1");
                $acc->execute([$memberId]);
                $accountId = (int)$acc->fetchColumn();
                if (!$accountId) throw new RuntimeException('Member has no savings account.');

                $result = record_savings_transaction(
                    $pdo, $accountId, 'withdrawal',
                    (float)$req['amount'], $date,
                    'Member portal request #' . $requestId .
                        ($req['notes'] ? ' — ' . $req['notes'] : ''),
                    $adminId
                );
                $entityId = (int)$result['id'];
                $summary = 'Withdrawal posted · ref ' . $result['reference'];
                break;

            case 'loan':
                $sched = build_loan_schedule(
                    (float)$req['amount'],
                    (float)$req['interest_rate'],
                    (int)$req['term_months'],
                    add_months($date, 1),
                    (string)($req['interest_method'] ?? 'declining')
                );
                $loanNo   = next_loan_no($pdo);
                $maturity = add_months(add_months($date, 1), (int)$req['term_months'] - 1);

                $pdo->prepare("
                    INSERT INTO loans
                    (loan_no, member_id, principal, interest_rate, interest_method, term_months,
                     total_interest, total_payable, amount_paid, balance, monthly_installment,
                     issue_date, first_due_date, maturity_date, purpose, notes, status,
                     approved_by, created_by)
                    VALUES (?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,'active',?,?)
                ")->execute([
                    $loanNo, $memberId,
                    (float)$req['amount'], (float)$req['interest_rate'],
                    $req['interest_method'] ?? 'declining', (int)$req['term_months'],
                    $sched['total_interest'], $sched['total_payable'],
                    $sched['total_payable'], $sched['monthly_installment'],
                    $date, add_months($date, 1), $maturity,
                    $req['purpose'], 'From member portal request #' . $requestId,
                    $adminId, $adminId,
                ]);
                $loanId = (int)$pdo->lastInsertId();

                $ins = $pdo->prepare("
                    INSERT INTO loan_schedules
                    (loan_id, installment_no, due_date, principal_due, interest_due, total_due)
                    VALUES (?,?,?,?,?,?)
                ");
                foreach ($sched['schedule'] as $s) {
                    $ins->execute([
                        $loanId, $s['installment_no'], $s['due_date'],
                        $s['principal_due'], $s['interest_due'], $s['total_due'],
                    ]);
                }
                $entityId = $loanId;
                $summary = 'Loan ' . $loanNo . ' issued · monthly ' . money($sched['monthly_installment']);
                break;

            case 'share_purchase':
                $result = record_share_purchase(
                    $pdo, $memberId, (int)$req['qty'], $date,
                    'savings', 'From member portal request #' . $requestId,
                    $adminId
                );
                $entityId = (int)$result['id'];
                $summary = $result['qty'] . ' shares · ' . money($result['total']) .
                           ' · receipt ' . $result['receipt_no'];
                break;
        }

        // Mark the request approved
        $pdo->prepare("
            UPDATE member_requests
            SET status='approved', decided_by=?, decided_at=NOW(),
                resulting_entity_id=?
            WHERE id = ?
        ")->execute([$adminId, $entityId, $requestId]);

        $pdo->commit();
        return ['entity_id' => $entityId, 'summary' => $summary];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Reject a request.
 */
function reject_member_request(PDO $pdo, int $requestId, int $adminId, string $reason): void {
    if (trim($reason) === '') {
        throw new InvalidArgumentException('A reason is required.');
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM member_requests WHERE id = ? FOR UPDATE");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();
        if (!$req) throw new RuntimeException('Request not found.');
        if ($req['status'] !== 'pending') {
            throw new RuntimeException('Request is already ' . $req['status'] . '.');
        }
        $pdo->prepare("
            UPDATE member_requests
            SET status='rejected', decided_by=?, decided_at=NOW(), decision_reason=?
            WHERE id = ?
        ")->execute([$adminId, $reason, $requestId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Cancel a request (only the member can cancel, only while pending).
 */
function cancel_member_request(PDO $pdo, int $requestId, int $memberId): void {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM member_requests WHERE id = ? FOR UPDATE");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();
        if (!$req) throw new RuntimeException('Request not found.');
        if ((int)$req['member_id'] !== $memberId) {
            throw new RuntimeException('Not your request.');
        }
        if ($req['status'] !== 'pending') {
            throw new RuntimeException('Only pending requests can be cancelled.');
        }
        $pdo->prepare("UPDATE member_requests SET status='cancelled' WHERE id = ?")
            ->execute([$requestId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}