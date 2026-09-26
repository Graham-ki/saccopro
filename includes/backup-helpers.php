<?php
declare(strict_types=1);

/**
 * Tables we manage. Order matters for foreign keys — parents first,
 * children after. Restore wipes them in reverse order.
 */
function backup_tables(): array {
    return [
        'users',
        'settings',
        'members',
        'savings_accounts',
        'savings_transactions',
        'loans',
        'loan_schedules',
        'loan_repayments',
        'activity_log',
    ];
}

/**
 * Dump a table to INSERT statements (batched).
 * Skips auto-increment value columns only in the sense that we don't
 * reset AUTO_INCREMENT — the INSERTs carry ids so relationships survive.
 */
function dump_table(PDO $pdo, string $table): string {
    $out = "\n--\n-- Data for table `$table`\n--\n";

    // Drop + recreate
    $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
    $out .= "DROP TABLE IF EXISTS `$table`;\n";
    $out .= $create['Create Table'] . ";\n\n";

    // Fetch rows
    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll();
    if (!$rows) {
        $out .= "-- (empty)\n";
        return $out;
    }

    $cols = array_keys($rows[0]);
    $colList = '`' . implode('`, `', $cols) . '`';

    // Batch 500 rows per INSERT
    $batch = [];
    foreach ($rows as $row) {
        $vals = [];
        foreach ($row as $v) {
            if ($v === null) {
                $vals[] = 'NULL';
            } elseif (is_int($v) || is_float($v)) {
                $vals[] = (string)$v;
            } else {
                $vals[] = $pdo->quote((string)$v);
            }
        }
        $batch[] = '(' . implode(', ', $vals) . ')';

        if (count($batch) >= 500) {
            $out .= "INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $batch) . ";\n";
            $batch = [];
        }
    }
    if ($batch) {
        $out .= "INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $batch) . ";\n";
    }

    return $out;
}

/**
 * Build a full SQL dump string. Returns the SQL text.
 */
function build_backup_sql(PDO $pdo): string {
    $sql  = "-- SCMS database backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Please review and keep this file safe.\n\n";

    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
    $sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    $sql .= "SET time_zone = '+03:00';\n\n";

    foreach (backup_tables() as $t) {
        try {
            $sql .= dump_table($pdo, $t);
        } catch (Throwable $e) {
            $sql .= "-- Skipped `$t`: " . $e->getMessage() . "\n";
        }
    }

    $sql .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";
    return $sql;
}

/**
 * Execute an uploaded SQL file.
 * Splits on ";\n" while respecting quoted strings crudely.
 * Returns [statements_run, errors].
 */
function run_sql_file(PDO $pdo, string $sql): array {
    // Remove BOM if present
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);

    // Split on semicolons that end lines (crude but works for mysqldump-style files)
    $chunks = [];
    $buf = '';
    $inString = false;
    $strChar = '';
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';

        if (!$inString && ($ch === "'" || $ch === '"' || $ch === '`')) {
            $inString = true;
            $strChar = $ch;
        } elseif ($inString && $ch === $strChar && $prev !== '\\') {
            $inString = false;
        }

        $buf .= $ch;

        if (!$inString && $ch === ';') {
            $trim = trim($buf);
            if ($trim !== '') $chunks[] = rtrim($trim, ';');
            $buf = '';
        }
    }
    $tail = trim($buf);
    if ($tail !== '') $chunks[] = $tail;

    $run = 0;
    $errors = [];

    foreach ($chunks as $stmt) {
        // Skip pure comments
        $stripped = trim($stmt);
        if ($stripped === '' || str_starts_with($stripped, '--') || str_starts_with($stripped, '/*')) {
            continue;
        }
        try {
            $pdo->exec($stmt);
            $run++;
        } catch (Throwable $e) {
            $errors[] = 'Failed: ' . substr($stripped, 0, 120) . '… → ' . $e->getMessage();
        }
    }

    return [$run, $errors];
}