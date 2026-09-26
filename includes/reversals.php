<?php
declare(strict_types=1);

/**
 * Reverse a savings transaction (deposit or withdrawal).
 * Posts a counter-entry and marks the original as reversed.
 * Atomic. Safe against double-reversal.
 */
function reverse_savings_transaction(
    PDO $pdo,
    int $txnId,
    int $userId,
    ?string $reason = null
): array {
    $pdo->beginTransaction();
    try {
        // Lock the original transaction row
        $lock = $pdo->prepare("
            SELECT * FROM savings_transactions
            WHERE id = ? FOR UPDATE
        ");
        $lock->execute([$txnId]);
        $orig = $lock->fetch();

        if (!$orig) throw new RuntimeException('Transaction not found.');
        if ((int)$orig['is_reversed'] === 1) {
            throw new RuntimeException('This transaction has already been reversed.');
        }
        if ((int)$orig['reversal_of_id'] > 0) {
            throw new RuntimeException('Cannot reverse a reversal entry.');
        }

        // Lock the account
        $acctLock = $pdo->prepare("SELECT * FROM savings_accounts WHERE id = ? FOR UPDATE");
        $acctLock->execute([(int)$orig['account_id']]);
        $acct = $acctLock->fetch();
        if (!$acct) throw new RuntimeException('Savings account not found.');

        $current = (float)$acct['balance'];

        // Counter-entry type and amount
        $counterType = $orig['type'] === 'deposit' ? 'withdrawal' : 'deposit';
        $amount      = (float)$orig['amount'];

        // If we're effectively withdrawing after reversing a deposit, guard overdraft
        if ($counterType === 'withdrawal' && $current < $amount) {
            throw new RuntimeException(
                'Cannot reverse: member balance is now ' . money($current) .
                ' but reversal needs ' . money($amount) . '. Reverse later transactions first.'
            );
        }

        $newBalance = $counterType === 'deposit'
            ? $current + $amount
            : $current - $amount;

        // Insert counter-entry
        $ref = function_exists('next_txn_reference') ? next_txn_reference($pdo) : ('REV-' . time());
        $reasonText = $reason ? 'Reversal of #' . $txnId . ': ' . $reason : 'Reversal of #' . $txnId;

        $ins = $pdo->prepare("
            INSERT INTO savings_transactions
            (account_id, type, amount, balance_after, reference, transaction_date,
             notes, recorded_by, reversed_by_id, reversal_of_id, is_reversed)
            VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, NULL, ?, 0)
        ");
        $ins->execute([
            (int)$orig['account_id'],
            $counterType,
            $amount,
            $newBalance,
            $ref,
            $reasonText,
            $userId,
            $txnId,
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Mark original as reversed
        $pdo->prepare("
            UPDATE savings_transactions
            SET is_reversed = 1, reversed_by_id = ?
            WHERE id = ?
        ")->execute([$userId, $txnId]);

        // Update account balance
        $pdo->prepare("UPDATE savings_accounts SET balance = ? WHERE id = ?")
            ->execute([$newBalance, (int)$orig['account_id']]);

        $pdo->commit();
        return [
            'reversal_id' => $newId,
            'reference'   => $ref,
            'new_balance' => $newBalance,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Reverse a loan repayment.
 * Rebuilds the loan's installment schedule for the affected installments,
 * then recomputes the loan's aggregate balance/status.
 */
function reverse_loan_repayment(
    PDO $pdo,
    int $repaymentId,
    int $userId,
    ?string $reason = null
): array {
    $pdo->beginTransaction();
    try {
        // Lock repayment row
        $lock = $pdo->prepare("SELECT * FROM loan_repayments WHERE id = ? FOR UPDATE");
        $lock->execute([$repaymentId]);
        $rep = $lock->fetch();

        if (!$rep) throw new RuntimeException('Repayment not found.');
        if ((int)$rep['is_reversed'] === 1) {
            throw new RuntimeException('This repayment has already been reversed.');
        }
        if ((int)$rep['reversal_of_id'] > 0) {
            throw new RuntimeException('Cannot reverse a reversal entry.');
        }

        $loanId = (int)$rep['loan_id'];

        // Lock loan
        $loanLock = $pdo->prepare("SELECT * FROM loans WHERE id = ? FOR UPDATE");
        $loanLock->execute([$loanId]);
        $loan = $loanLock->fetch();
        if (!$loan) throw new RuntimeException('Loan not found.');

        // Insert a counter-repayment row (negative amount) for the audit trail
        $ref = function_exists('next_repayment_reference')
            ? next_repayment_reference($pdo)
            : ('REV-' . time());
        $reasonText = $reason ? 'Reversal of #' . $repaymentId . ': ' . $reason : 'Reversal of #' . $repaymentId;

        // Insert with negative amounts so aggregate SUM naturally cancels
        $ins = $pdo->prepare("
            INSERT INTO loan_repayments
            (loan_id, amount, principal_portion, interest_portion, payment_date,
             reference, method, notes, recorded_by, reversal_of_id)
            VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $loanId,
            -1 * (float)$rep['amount'],
            -1 * (float)$rep['principal_portion'],
            -1 * (float)$rep['interest_portion'],
            $ref,
            $rep['method'],
            $reasonText,
            $userId,
            $repaymentId,
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Mark original as reversed
        $pdo->prepare("
            UPDATE loan_repayments
            SET is_reversed = 1, reversed_by_id = ?
            WHERE id = ?
        ")->execute([$userId, $repaymentId]);

        // --- Recompute loan_schedules for this loan from scratch ---
        // Wipe all amount_paid/status, then re-apply every non-reversed repayment oldest-first.
        $pdo->prepare("
            UPDATE loan_schedules
            SET amount_paid = 0, status = 'pending', paid_at = NULL
            WHERE loan_id = ?
        ")->execute([$loanId]);

        // Fetch repayments in chronological order, skipping the original (now reversed) ones
        $reps = $pdo->prepare("
            SELECT * FROM loan_repayments
            WHERE loan_id = ? AND is_reversed = 0
            ORDER BY payment_date ASC, id ASC
        ");
        $reps->execute([$loanId]);
        $all = $reps->fetchAll();

        foreach ($all as $r) {
            $amt = (float)$r['amount'];
            if ($amt <= 0) continue; // skip reversal rows
            if (function_exists('allocate_repayment')) {
                allocate_repayment($pdo, $loanId, $amt, (string)$r['payment_date']);
            }
        }

        if (function_exists('refresh_loan_totals')) {
            refresh_loan_totals($pdo, $loanId);
        }

        $pdo->commit();
        return ['reversal_id' => $newId, 'reference' => $ref];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}