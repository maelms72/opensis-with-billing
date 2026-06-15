<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<pre>\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "APP_DIR env: " . (getenv('APP_DIR') ?: '(not set)') . "\n\n";

// Check DatabaseInc.php was written
$db_inc = '/var/www/html/DatabaseInc.php';
if (!file_exists($db_inc)) {
    echo "MISSING: $db_inc — entrypoint did not write it\n";
} else {
    echo "EXISTS: $db_inc\n";
    include $db_inc;
    echo "DB_HOST: " . (defined('DB_HOST') ? DB_HOST : '(not defined)') . "\n";
    echo "DB_PORT: " . (defined('DB_PORT') ? DB_PORT : '(not defined)') . "\n";
    echo "DB_NAME: " . (defined('DB_NAME') ? DB_NAME : '(not defined)') . "\n";
    echo "DB_USER: " . (defined('DB_USER') ? DB_USER : '(not defined)') . "\n";
    echo "DB_PASS: " . (defined('DB_PASS') ? strlen(DB_PASS) . " chars" : '(not defined)') . "\n\n";

    // Try connecting
    $m = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    if ($m->connect_errno) {
        echo "DB CONNECTION FAILED: " . $m->connect_error . "\n";
    } else {
        echo "DB CONNECTION OK\n";
        $m->close();
    }
}

// Check Warehouse.php exists
echo "\nWarehouse.php: " . (file_exists('/var/www/html/Warehouse.php') ? 'exists' : 'MISSING') . "\n";
echo "LoginInc.php:  " . (file_exists('/var/www/html/LoginInc.php')  ? 'exists' : 'MISSING') . "\n";
echo "ConfigInc.php: " . (file_exists('/var/www/html/ConfigInc.php')  ? 'exists' : 'MISSING') . "\n";

echo "</pre>\n";
