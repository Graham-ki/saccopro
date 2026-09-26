<?php
declare(strict_types=1);

/** Validate/normalize from/to dates from $_GET. */
function report_range(): array {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-d');

    $valid = static function (string $d): bool {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    };

    if (!$valid($from)) $from = date('Y-m-01');
    if (!$valid($to))   $to   = date('Y-m-d');

    if (strtotime($from) > strtotime($to)) {
        [$from, $to] = [$to, $from];
    }

    return [$from, $to];
}

/** Format a number for CSV (no thousands separators). */
function csv_num($n): string {
    return number_format((float)$n, 2, '.', '');
}

/** Stream a 2D array as a CSV download and exit. */
function csv_download(string $filename, array $headers, array $rows): void {
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/** Friendly label for a month number. */
function month_label(int $m): string {
    return date('M', mktime(0, 0, 0, $m, 1));
}

/** Aging bucket for a due date vs today. */
function aging_bucket(string $dueDate, string $status): string {
    if ($status === 'paid') return 'Paid';
    $days = (int)floor((time() - strtotime($dueDate)) / 86400);
    if ($days < 0)   return 'Not yet due';
    if ($days === 0) return 'Due today';
    if ($days <= 30) return '1–30 days';
    if ($days <= 60) return '31–60 days';
    if ($days <= 90) return '61–90 days';
    return '90+ days';
}