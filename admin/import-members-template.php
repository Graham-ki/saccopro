<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_login();

$rows = [
    ['first_name','last_name','phone','email','gender','dob','address','id_number','join_date','notes'],
    ['John','Mukasa','+256700111222','john@example.com','male','1990-04-15','Kampala','CM900001','2024-01-15','Sample row'],
    ['Jane','Doe','+256700333444','jane@example.com','female','1988-09-22','Entebbe','CM900002','2024-02-01',''],
    ['Peter','Okello','+256700555666','','male','','Gulu','','',''],
];

if (!headers_sent()) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="members-import-template.csv"');
}

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
foreach ($rows as $r) fputcsv($out, $r);
fclose($out);
exit;