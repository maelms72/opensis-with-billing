<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<pre>\n";

include '/var/www/html/DatabaseInc.php';
$m = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
if ($m->connect_errno) { echo "DB FAILED: " . $m->connect_error; exit; }

// app table
$rows = $m->query("SELECT name, value FROM app");
echo "app table:\n";
while ($r = $rows->fetch_assoc()) echo "  {$r['name']} = {$r['value']}\n";

// Key tables
foreach (['login_authentication','system_preference_misc','school_years','user_profiles'] as $t) {
    $c = $m->query("SELECT COUNT(*) AS c FROM `$t`")->fetch_assoc()['c'];
    echo "$t: $c rows\n";
}

// Simulate UpgradeInc logic
echo "\nUpgradeInc simulation:\n";
$build = $m->query("SELECT value FROM app WHERE name='build'")->fetch_assoc();
echo "  build value: " . ($build['value'] ?? '(null)') . "\n";
if (!empty($build['value'])) {
    $v = $build['value'];
    $month = substr($v,0,2); $day = substr($v,2,2); $year = substr($v,4,4);
    $build_date = mktime(0,0,0,(int)$month,(int)$day,(int)$year);
    $cutoff     = mktime(0,0,0,5,28,2009);
    echo "  build_date: $build_date, cutoff: $cutoff\n";
    echo "  would redirect to upgrade: " . ($build_date < $cutoff ? "YES" : "NO") . "\n";
} else {
    echo "  app.build is empty — UpgradeInc will redirect to install/index.php?upreq=true\n";
}

// Try including LoginInc.php with output buffering to catch any redirect/output
echo "\nLoginInc.php test (output captured):\n";
// Check for early redirects in index.php flow
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/index.php';
ob_start();
$headers_before = headers_list();
// Just include the lang files (safe)
include '/var/www/html/lang/supportedLanguages.php';
include '/var/www/html/lang/lang_en.php';
$out = ob_get_clean();
echo "  lang includes: OK\n";

// Check install/index.php exists and what it does
echo "\ninstall/index.php first 5 lines:\n";
$lines = file('/var/www/html/install/index.php');
foreach (array_slice($lines, 0, 5) as $l) echo "  " . htmlspecialchars($l);

$m->close();
echo "\n</pre>\n";
