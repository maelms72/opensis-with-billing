<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<pre>\n";

include '/var/www/html/DatabaseInc.php';
$m = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
if ($m->connect_errno) { echo "DB FAILED: " . $m->connect_error; exit; }

// Key table row counts
foreach (['app','login_authentication','system_preference_misc','school_years','user_profiles','login_message'] as $t) {
    $c = $m->query("SELECT COUNT(*) AS c FROM `$t`")->fetch_assoc()['c'];
    echo "$t: $c rows\n";
}

// Duplicate app rows?
$build = $m->query("SELECT value FROM app WHERE name='build' LIMIT 1")->fetch_assoc();
echo "app.build (first): " . ($build['value'] ?? '(null)') . "\n";

// Check system_preference_misc columns
$cols = $m->query("SHOW COLUMNS FROM system_preference_misc");
echo "\nsystem_preference_misc columns:\n";
while ($r = $cols->fetch_assoc()) echo "  " . $r['Field'] . "\n";

// Try rendering LoginInc.php with errors on
echo "\nAttempting LoginInc.php render:\n";
chdir('/var/www/html');
$_SERVER['PHP_SELF']    = '/index.php';
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = []; $_POST = []; $_REQUEST = [];

ob_start();
try {
    require_once '/var/www/html/LoginInc.php';
} catch (Throwable $e) {
    echo "\nEXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}
$out = ob_get_clean();
echo "Output length: " . strlen($out) . " bytes\n";
if (strlen($out) > 0) {
    echo "First 500 chars:\n" . htmlspecialchars(substr($out, 0, 500)) . "\n";
} else {
    echo "No output produced.\n";
}

$m->close();
echo "\n</pre>\n";
