<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/_helpers.php';

$report = $_GET['report'] ?? 'overview';
[$from, $to] = report_range();

$filename = "scms-{$report}-{$from}_to_{$to}.csv";

switch ($report) {

    case 'members':
        $rows = [];
        $q = $pdo->query("
            SELECT m.member_no, m.first_name, m.last_name, m.phone, m.email,
                   m.gender, m.dob, m.join_date, m.status,
                   sa.account_no, sa.balance
            FROM members m
            LEFT JOIN savings_accounts sa ON sa.member_id = m.id
            ORDER BY m.last_name, m.first_name
        ");
        foreach ($q->fetchAll() as $r) {
            $rows[] = [
                $r['member_no'], $r['first_name'], $r['last_name'],
                $r['phone'], $r['email'], $r['gender'], $r['dob'],
                $r['join_date'], $r['status'],
                $r['account_no'], csv_num($r['balance'] ?? 0),
            ];
        }
        csv_download($filename, [
            'Member No','First Name','Last Name','Phone','Email','Gender','DOB',
            'Join Date','Status','Account No','Balance',
        ], $rows);
        break;

    case 'savings':
        $stmt = $pdo->prepare("
            SELECT st.transaction_date, st.reference, st.type, st.amount, st.balance_after,
                   m.member_no, CONCAT(m.first_name,' ',m.last_name) AS member_name,
                   sa.account_no, st.notes
            FROM savings_transactions st
            JOIN savings_accounts sa ON sa.id = st.account_id
            JOIN members m ON m.id = sa.member_id
            WHERE st.transaction_date BETWEEN :from1 AND :to1
            ORDER BY st.transaction_date, st.id
        ");
        $stmt->execute([':from1' => $from, ':to1' => $to]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                $r['transaction_date'], $r['reference'], $r['type'],
                csv_num($r['amount']), csv_num($r['balance_after']),
                $r['member_no'], $r['member_name'], $r['account_no'],
                $r['notes'] ?? '',
            ];
        }
        csv_download($filename, [
            'Date','Reference','Type','Amount','Balance After',
            'Member No','Member Name','Account No','Notes',
        ], $rows);
        break;

    case 'loans':
        $stmt = $pdo->prepare("
            SELECT l.loan_no, m.member_no, CONCAT(m.first_name,' ',m.last_name) AS member_name,
                   l.principal, l.interest_rate, l.interest_method, l.term_months,
                   l.total_payable, l.amount_paid, l.balance,
                   l.issue_date, l.first_due_date, l.maturity_date, l.status
            FROM loans l
            JOIN members m ON m.id = l.member_id
            WHERE l.issue_date BETWEEN :from1 AND :to1
            ORDER BY l.issue_date, l.id
        ");
        $stmt->execute([':from1' => $from, ':to1' => $to]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                $r['loan_no'], $r['member_no'], $r['member_name'],
                csv_num($r['principal']), csv_num($r['interest_rate']),
                $r['interest_method'], $r['term_months'],
                csv_num($r['total_payable']), csv_num($r['amount_paid']),
                csv_num($r['balance']),
                $r['issue_date'], $r['first_due_date'], $r['maturity_date'],
                $r['status'],
            ];
        }
        csv_download($filename, [
            'Loan No','Member No','Member Name','Principal','Rate %','Method','Term',
            'Total Payable','Amount Paid','Balance',
            'Issue Date','First Due','Maturity','Status',
        ], $rows);
        break;

    case 'income':
        $stmt = $pdo->prepare("
            SELECT r.payment_date, r.reference, l.loan_no,
                   m.member_no, CONCAT(m.first_name,' ',m.last_name) AS member_name,
                   r.amount, r.principal_portion, r.interest_portion,
                   r.method, r.notes
            FROM loan_repayments r
            JOIN loans l ON l.id = r.loan_id
            JOIN members m ON m.id = l.member_id
            WHERE r.payment_date BETWEEN :from1 AND :to1
            ORDER BY r.payment_date, r.id
        ");
        $stmt->execute([':from1' => $from, ':to1' => $to]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                $r['payment_date'], $r['reference'], $r['loan_no'],
                $r['member_no'], $r['member_name'],
                csv_num($r['amount']),
                csv_num($r['principal_portion']),
                csv_num($r['interest_portion']),
                $r['method'], $r['notes'] ?? '',
            ];
        }
        csv_download($filename, [
            'Date','Reference','Loan No','Member No','Member Name',
            'Amount','Principal','Interest','Method','Notes',
        ], $rows);
        break;

    case 'overview':
    default:
        $stmt = $pdo->prepare("
            SELECT
                (SELECT COALESCE(SUM(amount),0) FROM savings_transactions
                 WHERE type='deposit'    AND transaction_date BETWEEN :from1 AND :to1) AS deposits,
                (SELECT COALESCE(SUM(amount),0) FROM savings_transactions
                 WHERE type='withdrawal' AND transaction_date BETWEEN :from2 AND :to2) AS withdrawals,
                (SELECT COUNT(*) FROM members WHERE join_date BETWEEN :from3 AND :to3) AS new_members,
                (SELECT COUNT(*) FROM loans   WHERE issue_date BETWEEN :from4 AND :to4) AS new_loans,
                (SELECT COALESCE(SUM(principal),0) FROM loans
                 WHERE issue_date BETWEEN :from5 AND :to5)                              AS loaned,
                (SELECT COALESCE(SUM(amount),0) FROM loan_repayments
                 WHERE payment_date BETWEEN :from6 AND :to6)                            AS repayments,
                (SELECT COALESCE(SUM(interest_portion),0) FROM loan_repayments
                 WHERE payment_date BETWEEN :from7 AND :to7)                            AS interest
        ");
        $stmt->execute([
            ':from1' => $from, ':to1' => $to,
            ':from2' => $from, ':to2' => $to,
            ':from3' => $from, ':to3' => $to,
            ':from4' => $from, ':to4' => $to,
            ':from5' => $from, ':to5' => $to,
            ':from6' => $from, ':to6' => $to,
            ':from7' => $from, ':to7' => $to,
        ]);
        $r = $stmt->fetch();

        $rows = [
            ['Period',           "$from to $to"],
            ['Deposits',         csv_num($r['deposits'])],
            ['Withdrawals',      csv_num($r['withdrawals'])],
            ['Net flow',         csv_num((float)$r['deposits'] - (float)$r['withdrawals'])],
            ['New members',      $r['new_members']],
            ['New loans issued', $r['new_loans']],
            ['Principal issued', csv_num($r['loaned'])],
            ['Repayments',       csv_num($r['repayments'])],
            ['Interest earned',  csv_num($r['interest'])],
        ];
        csv_download($filename, ['Metric','Value'], $rows);
        break;
}