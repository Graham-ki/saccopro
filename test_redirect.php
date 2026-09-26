<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "Step 1: file loaded<br>";

require_once __DIR__ . '/includes/functions.php';
echo "Step 2: functions.php loaded<br>";

echo "Step 3: about to redirect in 2 seconds...<br>";
flush();

sleep(1);
redirect('/scms/index.php');
echo "Step 4: THIS SHOULD NEVER PRINT";