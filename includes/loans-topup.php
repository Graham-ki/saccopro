<?php
declare(strict_types=1);

/**
 * Top up a loan by adding more principal.
 *
 * Strategy:
 *   1. Keep all PAID installments intact (they're historical).
 *   2. Sum remaining unpaid principal + remaining unpaid interest.
 *   3. New total_payable = paid-portion-already + outstanding (old) + new principal + new interest.
 *   4. Rewrite ONLY the unpaid installments (delete + regenerate) based on:
 *        - remaining principal balance (old unpaid principal + top-up)
 *        - interest on that balance
 *        - term equal to the number of unpaid installments we just removed
 *        - first due date = max(maturity-ish, today + 1 month)
 *
 * This preserves history and treats the top-up as a fresh facility on the
 * remaining loan.
 */
function topup_loan(
    PDO $pdo,
    int $loanId,
    float $amount,
    string $topupDate,
    string $method,
    ?string $notes,
    int $userId
): array {
    if ($amount <= 0) {
        throw new InvalidArgumentException('Top-up amount must be positive.');
    }

    $pdo->beginTransaction();
    try {
        // Lock loan
        $lock = $pdo->prepare("SELECT * FROM loans WHERE id = ? FOR UPDATE");
        $lock->execute([$loanId]);
        $loan = $lock->fetch();

        if (!$loan) throw new RuntimeException('Loan not found.');
        if ($loan['status'] !== 'active') {
            throw new RuntimeException('Only active loans can be topped up.');
        }

        // Sum paid vs unpaid installments
        $sum = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'paid' THEN total_due     END), 0) AS paid_total,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_paid   END), 0) AS paid_amount,
                COALESCE(SUM(CASE WHEN status <> 'paid'
                                  THEN principal_due END), 0)                       AS remaining_principal,
                COALESCE(SUM(CASE WHEN status <> 'paid'
                                  THEN interest_due END), 0)                        AS remaining_interest,
                COUNT(CASE WHEN status <> 'paid' THEN 1 END)                       AS remaining_count
            FROM loan_schedules WHERE loan_id = ?
        ");
        $sum->execute([$loanId]);
        $s = $sum->fetch();

        $remainingCount = (int)$s['remaining_count'];
        if ($remainingCount < 1) {
            throw new RuntimeException('This loan has no outstanding installments to top up.');
        }

        // New principal = old principal + top-up
        $oldPrincipal = (float)$loan['principal'];
        $newPrincipal = $oldPrincipal + $amount;

        // Amount already paid toward the loan (both principal + interest)
        $paidAmount = (float)$loan['amount_paid'];

        // We now rebuild the remaining portion of the schedule:
        //   new_remaining_principal = old remaining principal + top-up amount
        //   interest on new_remaining_principal using the same method/rate/term
        $newRemainingPrincipal = (float)$s['remaining_principal'] + $amount;

        $sched = build_loan_schedule(
            $newRemainingPrincipal,
            (float)$loan['interest_rate'],
            $remainingCount,
            $topupDate,                 // first due = top-up date (or set to +1 month, see below)
            (string)$loan['interest_method']
        );

        // Recompute loan totals:
        //   total_payable = already paid + new total payable on the remaining portion
        $newRemainingPayable = (float)$sched['total_payable'];
        $newTotalPayable     = $paidAmount + $newRemainingPayable;

        $newTotalInterest = (float)$loan['total_interest'] + (float)$sched['total_interest'];
        $newBalance       = max(0, $newTotalPayable - $paidAmount);

        // Delete unpaid installments only
        $pdo->prepare("DELETE FROM loan_schedules WHERE loan_id = ? AND status <> 'paid'")
            ->execute([$loanId]);

        // Insert new installments, offsetting numbering from where the paid ones ended
        $maxPaidStmt = $pdo->prepare("
            SELECT COALESCE(MAX(installment_no), 0)
            FROM loan_schedules WHERE loan_id = ?
        ");
        $maxPaidStmt->execute([$loanId]);
        $startNo = (int)$maxPaidStmt->fetchColumn();

        $ins = $pdo->prepare("
            INSERT INTO loan_schedules
            (loan_id, installment_no, due_date, principal_due, interest_due, total_due)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($sched['schedule'] as $i => $row) {
            $ins->execute([
                $loanId,
                $startNo + $i + 1,
                $row['due_date'],
                $row['principal_due'],
                $row['interest_due'],
                $row['total_due'],
            ]);
        }

        // Update loan
        $pdo->prepare("
            UPDATE loans SET
                principal = ?,
                total_interest = ?,
                total_payable = ?,
                balance = ?,
                monthly_installment = ?,
                maturity_date = ?
            WHERE id = ?
        ")->execute([
            $newPrincipal,
            $newTotalInterest,
            $newTotalPayable,
            $newBalance,
            $sched['monthly_installment'],
            add_months($topupDate, $remainingCount - 1),
            $loanId,
        ]);

        // Record the top-up event
        $ins2 = $pdo->prepare("
            INSERT INTO loan_topups
            (loan_id, amount, new_principal, new_total_payable, new_balance,
             topup_date, method, notes, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins2->execute([
            $loanId, $amount, $newPrincipal, $newTotalPayable, $newBalance,
            $topupDate, $method, $notes, $userId,
        ]);
        $topupId = (int)$pdo->lastInsertId();

        $pdo->commit();

        return [
            'id'              => $topupId,
            'new_principal'   => $newPrincipal,
            'new_total'       => $newTotalPayable,
            'new_balance'     => $newBalance,
            'monthly'         => $sched['monthly_installment'],
            'installments'    => count($sched['schedule']),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}